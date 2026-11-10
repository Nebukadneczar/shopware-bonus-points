<?php declare(strict_types=1);

namespace MaxxpetBonus\Cart;

use MaxxpetBonus\Service\BonusService;
use MaxxpetBonus\Service\GiftService;
use MaxxpetBonus\Service\MemberService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Gratisprodukte als Position mit 0 €: „auto“ legt der Prozessor selbst in den Warenkorb,
 * bei „wahl“ entscheidet der Kunde (eine Position mit der Kennung maxxpet-gift-choice).
 * Es sind Positionen ohne Lagerbuchung; die Bestellung zeigt Artikelnummer und Bezeichnung zum Verpacken.
 */
class GiftCartProcessor implements CartProcessorInterface
{
    public const TYPE = 'maxxpet_gift';
    public const CHOICE_ID = 'maxxpet-gift-choice';

    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly GiftService $gifts,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $choice = $original->getLineItems()->get(self::CHOICE_ID);
        $customer = $context->getCustomer();
        $goods = $toCalculate->getLineItems()->filterGoods();

        if (
            !$this->bonus->isActive()
            || $customer === null
            || $customer->getGuest()
            || $goods->count() === 0
            || !$this->members->isParticipant($customer->getId())
        ) {
            if ($choice !== null) {
                $original->remove(self::CHOICE_ID);
            }

            return;
        }

        $factor = max(0.0001, (float) $context->getCurrency()->getFactor());
        $goodsEuro = (float) $goods->getPrices()->getTotalPriceAmount() / $factor;
        $levelIdx = $this->gifts->levelIndex($this->bonus->account($customer->getId())['level']['name']);
        $eligible = $this->gifts->eligible($goodsEuro, $levelIdx);

        // automatische Geschenke
        foreach ($eligible as $r) {
            if ($r['mode'] === 'auto') {
                $toCalculate->add($this->build('maxxpet-gift-' . substr(md5($r['sku']), 0, 12), $r, false));
            }
        }

        // Geschenk nach Wahl des Kunden
        if ($choice !== null) {
            $sku = (string) $choice->getPayloadValue('sku');
            $rule = null;
            foreach ($eligible as $r) {
                if ($r['mode'] === 'wahl' && $r['sku'] === $sku) {
                    $rule = $r;
                    break;
                }
            }
            if ($rule === null) {
                $original->remove(self::CHOICE_ID);

                return;
            }
            $toCalculate->add($this->build(self::CHOICE_ID, $rule, true));
        }
    }

    /**
     * @param array{sku:string,label:string,min:float,level:int,mode:string} $rule
     */
    private function build(string $id, array $rule, bool $removable): LineItem
    {
        $item = new LineItem($id, self::TYPE, null, 1);
        $item->setLabel('Gratis: ' . $rule['label']);
        $item->setGood(false);
        $item->setRemovable($removable);
        $item->setStackable(false);
        $item->setShippingCostAware(false);
        $item->setPrice(new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1));
        $item->setPayloadValue('sku', $rule['sku']);
        $item->setPayloadValue('giftLabel', $rule['label']);

        return $item;
    }
}
