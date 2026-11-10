<?php declare(strict_types=1);

namespace Bonus\Cart;

use Bonus\Service\BonusService;
use Bonus\Service\MemberService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Treuerabatt: Level mit Rabatt in Prozent. Die Position wird bei jeder Berechnung neu erzeugt,
 * sie ist nicht entfernbar und fällt weg, wenn der Kunde nicht (mehr) teilnimmt.
 */
class LevelDiscountCartProcessor implements CartProcessorInterface
{
    public const TYPE = 'bonus_level_discount';
    public const ID = 'bonus-level-discount';

    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly PercentagePriceCalculator $calculator,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $customer = $context->getCustomer();
        if ($customer === null || $customer->getGuest() || !$this->bonus->isActive() || !$this->bonus->levelsEnabled()) {
            return;
        }
        if (!$this->members->isParticipant($customer->getId())) {
            return;
        }

        $level = $this->bonus->account($customer->getId())['level'];
        $pct = (float) $level['discount'];
        if ($pct <= 0) {
            return;
        }
        $goods = $toCalculate->getLineItems()->filterGoods();
        if ($goods->count() === 0) {
            return;
        }

        $price = $this->calculator->calculate(-$pct, $goods->getPrices(), $context);
        if ($price->getTotalPrice() >= 0) {
            return;
        }

        $item = new LineItem(self::ID, self::TYPE, null, 1);
        $item->setLabel(sprintf('Treuerabatt %s (%s %%)', $level['name'], rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')));
        $item->setGood(false);
        $item->setRemovable(false);
        $item->setStackable(false);
        $item->setShippingCostAware(false);
        $item->setPrice($price);
        $item->setPriceDefinition(new PercentagePriceDefinition(-$pct));
        $item->setPayloadValue('levelName', $level['name']);

        $toCalculate->add($item);
    }
}
