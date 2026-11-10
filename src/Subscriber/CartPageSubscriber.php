<?php declare(strict_types=1);

namespace MaxxpetBonus\Subscriber;

use MaxxpetBonus\Cart\BonusCartProcessor;
use MaxxpetBonus\Cart\GiftCartProcessor;
use MaxxpetBonus\Cart\LevelDiscountCartProcessor;
use MaxxpetBonus\Service\BonusService;
use MaxxpetBonus\Service\GiftService;
use MaxxpetBonus\Service\MemberService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Liefert Warenkorb, Kasse und Off-Canvas die Werte für die Punkte-Box (Erweiterung „maxxpetBonus“).
 */
class CartPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly GiftService $gifts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onPage',
            CheckoutConfirmPageLoadedEvent::class => 'onPage',
            OffcanvasCartPageLoadedEvent::class => 'onPage',
        ];
    }

    public function onPage(CheckoutCartPageLoadedEvent|CheckoutConfirmPageLoadedEvent|OffcanvasCartPageLoadedEvent $event): void
    {
        try {
            if (!$this->bonus->isActive()) {
                return;
            }
            $context = $event->getSalesChannelContext();
            $cart = $event->getPage()->getCart();
            $customer = $context->getCustomer();
            $loggedIn = $customer !== null && !$customer->getGuest();

            $factor = max(0.0001, (float) $context->getCurrency()->getFactor());
            $unit = $this->bonus->pointValue() * $factor;
            $combine = $this->bonus->combineWithLevelDiscount();

            $items = $cart->getLineItems();
            $redeemItem = $items->get(BonusCartProcessor::ID);
            $redeemed = $redeemItem ? (int) $redeemItem->getPayloadValue('pointsRedeemed') : 0;
            $redeemedAmount = $redeemItem && $redeemItem->getPrice() ? abs($redeemItem->getPrice()->getTotalPrice()) : 0.0;
            $levelItem = $items->get(LevelDiscountCartProcessor::ID);
            $levelAmount = $levelItem && $levelItem->getPrice() ? abs($levelItem->getPrice()->getTotalPrice()) : 0.0;

            $after = (float) $items->getPrices()->getTotalPriceAmount();
            $before = $after + $redeemedAmount;
            if (!$combine && $levelAmount > 0 && $redeemed === 0) {
                $before += $levelAmount; // beim Einlösen würde der Treuerabatt entfallen
            }
            $goodsValue = (float) $items->filterGoods()->getPrices()->getTotalPriceAmount();

            $levels = $this->bonus->levels();
            $mult = $levels[0]['mult'];
            $levelName = $levels[0]['name'];
            $levelPct = $levels[0]['discount'];
            $available = 0;
            $levelNext = '';
            $levelMissing = 0;
            $levelProgress = 0;
            $state =['eligible' => false, 'member' => false, 'blocked' => false, 'groupAllowed' => false];
            $participant = false;
            $levelIdx = 0;
            if ($loggedIn) {
                $state = $this->members->state($customer->getId());
                $participant = $this->members->isParticipant($customer->getId());
                if ($participant) {
                    $acc = $this->bonus->account($customer->getId());
                    $available = $acc['available'];
                    $mult = $acc['level']['mult'];
                    $levelName = $acc['level']['name'];
                    $levelPct = $acc['level']['discount'];
                    $levelIdx = $this->gifts->levelIndex($levelName);
                    $levelNext = $acc['next'] !== null ? (string) $acc['next']['name'] : '';
                    $levelMissing = (int) $acc['missing'];
                    $levelProgress = (int) $acc['progress'];
                }
            }
            $canJoin = $loggedIn && !$participant && $this->members->enrollment() === 'optin' && $state['eligible'] && !$state['member'];

            $earn = $participant || !$loggedIn
                ? (int) max(0, floor(max(0.0, $after) / $factor * $this->bonus->pointsPerEuro() * $mult))
                : 0;

            $cap = $before;
            $pct = $this->bonus->maxRedeemPercent();
            if ($pct > 0) {
                $cap = $before * $pct / 100;
            }
            $maxByCart = (int) max(0, floor(round($cap / $unit, 6)));
            $max = min($available, $maxByCart);
            $min = $this->bonus->minRedeem();

            $minOrder = $this->bonus->minOrderValue() * $factor;
            $belowMin = $minOrder > 0 && $goodsValue < $minOrder;

            // Gratisprodukte
            $goodsEuro = $goodsValue / $factor;
            $choices = [];
            $selected = $items->get(GiftCartProcessor::CHOICE_ID);
            $nextGift = null;
            if ($participant) {
                foreach ($this->gifts->eligible($goodsEuro, $levelIdx) as $r) {
                    if ($r['mode'] === 'wahl') {
                        $choices[] = ['sku' => $r['sku'], 'label' => $r['label']];
                    }
                }
                $n = $this->gifts->nextByValue($goodsEuro, $levelIdx);
                if ($n !== null) {
                    $nextGift = ['label' => $n['label'], 'missing' => ($n['min'] - $goodsEuro) * $factor];
                }
            }

            $event->getPage()->addExtension('maxxpetBonus', new ArrayStruct([
                'loggedIn' => $loggedIn,
                'participant' => $participant,
                'canJoin' => $canJoin,
                'available' => $available,
                'availableValue' => $available * $unit,
                'unit' => $unit,
                'min' => $min,
                'max' => $max,
                'canRedeem' => $participant && !$belowMin && $max >= $min,
                'redeemed' => $redeemed,
                'redeemedAmount' => $redeemedAmount,
                'earn' => $earn,
                'level' => $levelName,
                'levelsOn' => $this->bonus->levelsEnabled(),
                'levelNext' => $levelNext,
                'levelMissing' => $levelMissing,
                'levelProgress' => $levelProgress,
                'levelDiscountPct' => $levelPct,
                'levelDiscountAmount' => $levelAmount,
                'combine' => $combine,
                'minOrderValue' => $minOrder,
                'belowMinOrder' => $participant && $belowMin,
                'missingToMinOrder' => max(0.0, $minOrder - $goodsValue),
                'giftChoices' => $choices,
                'giftSelected' => $selected ? (string) $selected->getPayloadValue('sku') : '',
                'giftNext' => $nextGift,
                'currency' => $context->getCurrency()->getIsoCode(),
            ]));
        } catch (\Throwable $e) {
            $this->logger->error('MaxxpetBonus (cart-page): ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
