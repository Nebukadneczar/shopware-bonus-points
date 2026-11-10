<?php declare(strict_types=1);

namespace MaxxpetBonus\Cart;

use MaxxpetBonus\Service\BonusService;
use MaxxpetBonus\Service\MemberService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Rechnet die Rabattposition „Eingelöste Bonuspunkte“ im Warenkorb.
 * Der Kunde wünscht eine Punktzahl (Payload „points“); hier wird sie geprüft und begrenzt auf
 * Kontostand, Warenkorbwert und ggf. Höchstanteil. Unter dem Mindestwert fällt die Position weg.
 * Läuft nach dem Treuerabatt (Priorität 3000 < 3100), damit beide zusammen nie über den Warenwert gehen.
 */
class BonusCartProcessor implements CartProcessorInterface
{
    public const TYPE = 'maxxpet_bonus';
    public const ID = 'maxxpet-bonus';

    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly AbsolutePriceCalculator $calculator,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $items = $original->getLineItems()->filterType(self::TYPE);
        if ($items->count() === 0) {
            return;
        }

        $customer = $context->getCustomer();
        $goods = $toCalculate->getLineItems()->filterGoods();
        if (
            !$this->bonus->isActive()
            || $customer === null
            || $customer->getGuest()
            || $goods->count() === 0
            || !$this->members->isParticipant($customer->getId())
        ) {
            foreach ($items as $item) {
                $original->remove($item->getId());
            }

            return;
        }

        $factor = max(0.0001, (float) $context->getCurrency()->getFactor());
        $unit = $this->bonus->pointValue() * $factor; // Wert eines Punktes in der Warenkorb-Währung

        // Treuerabatt und Punkte nur zusammen, wenn erlaubt
        $levelItem = $toCalculate->getLineItems()->get(LevelDiscountCartProcessor::ID);
        $levelAmount = $levelItem !== null && $levelItem->getPrice() !== null ? abs($levelItem->getPrice()->getTotalPrice()) : 0.0;
        $dropLevel = $levelItem !== null && !$this->bonus->combineWithLevelDiscount();

        $total = (float) $toCalculate->getLineItems()->getPrices()->getTotalPriceAmount();
        if ($dropLevel) {
            $total += $levelAmount;
        }

        // Mindest-Warenwert zum Einlösen
        $minOrder = $this->bonus->minOrderValue() * $factor;
        $goodsValue = (float) $goods->getPrices()->getTotalPriceAmount();
        if ($minOrder > 0 && $goodsValue < $minOrder) {
            foreach ($items as $item) {
                $original->remove($item->getId());
            }

            return;
        }

        $available = $this->bonus->account($customer->getId())['available'];

        $first = true;
        foreach ($items as $item) {
            if (!$first) {
                $original->remove($item->getId());
                continue;
            }
            $first = false;

            $cap = $total;
            $pct = $this->bonus->maxRedeemPercent();
            if ($pct > 0) {
                $cap = $total * $pct / 100;
            }
            $maxByCart = (int) floor(round($cap / $unit, 6));
            $points = min((int) $item->getPayloadValue('points'), $available, $maxByCart);

            if ($points < $this->bonus->minRedeem()) {
                $original->remove($item->getId());
                continue;
            }

            $amount = min(round($points * $unit, 2), round($total, 2));
            if ($amount <= 0) {
                $original->remove($item->getId());
                continue;
            }

            if ($dropLevel) {
                $toCalculate->remove(LevelDiscountCartProcessor::ID);
                $goods = $toCalculate->getLineItems()->filterGoods();
            }

            $price = $this->calculator->calculate(-$amount, $goods->getPrices(), $context);

            $item->setLabel('Eingelöste Bonuspunkte (' . $points . ' Punkte)');
            $item->setPrice($price);
            $item->setPriceDefinition(new AbsolutePriceDefinition(-$amount));
            $item->setGood(false);
            $item->setRemovable(true);
            $item->setStackable(false);
            $item->setShippingCostAware(false);
            $item->setPayloadValue('points', $points);
            $item->setPayloadValue('pointsRedeemed', $points);

            $toCalculate->add($item);
        }
    }
}
