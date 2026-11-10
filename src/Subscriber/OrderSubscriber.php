<?php declare(strict_types=1);

namespace MaxxpetBonus\Subscriber;

use Doctrine\DBAL\Connection;
use MaxxpetBonus\Service\BonusService;
use MaxxpetBonus\Service\MemberService;
use MaxxpetBonus\Service\ReferralService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bestellung aufgegeben → Beitritt per Checkbox, eingelöste Punkte abbuchen.
 * Zahlung „Bezahlt“ → Punkte (mit Sperrfrist) und Empfehlungsprovision.
 * Storno/Erstattung → Rückbuchung. Fehler werden nur protokolliert und stören nie den Bestellablauf.
 */
class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly ReferralService $referrals,
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onPlaced',
            'state_enter.order_transaction.state.paid' => 'onPaid',
            'state_enter.order_transaction.state.refunded' => 'onTransactionRefunded',
            'state_enter.order_transaction.state.cancelled' => 'onTransactionCancelled',
            'state_enter.order.state.cancelled' => 'onOrderCancelled',
        ];
    }

    public function onPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->run('placed', function () use ($event): void {
            $order = $event->getOrder();
            $orderId = $order->getId();

            // Beitritt über die Checkbox an der Kasse
            $request = $this->requestStack->getCurrentRequest();
            if ($request !== null && $request->request->get('maxxpetBonusJoin')) {
                $customerId = $order->getOrderCustomer()?->getCustomerId();
                if ($customerId !== null && $this->bonus->isActive()) {
                    $this->members->join($customerId, 'checkout');
                }
            }

            $this->bonus->redeemFromOrder($orderId);
        });
    }

    public function onPaid(StateMachineStateChangeEvent $event): void
    {
        $this->run('paid', function () use ($event): void {
            if (!$this->bonus->isActive()) {
                return;
            }
            $orderId = $this->orderIdOfTransaction($event->getTransition()->getEntityId());
            if ($orderId === null) {
                return;
            }
            $this->bonus->awardForOrder($orderId);
            $this->referrals->commissionForOrder($orderId);
        });
    }

    /** Zahlung erstattet: Verdienst zurückbuchen und eingelöste Punkte zurückgeben (wenn keine andere Zahlung „bezahlt“ ist). */
    public function onTransactionRefunded(StateMachineStateChangeEvent $event): void
    {
        $this->run('transaction-refunded', function () use ($event): void {
            $orderId = $this->reversibleOrder($event);
            if ($orderId !== null) {
                $this->reverseAll($orderId, true);
            }
        });
    }

    /** Zahlung abgebrochen: nur den Verdienst zurückbuchen. Eingelöste Punkte bleiben, bis die Bestellung selbst storniert wird. */
    public function onTransactionCancelled(StateMachineStateChangeEvent $event): void
    {
        $this->run('transaction-cancelled', function () use ($event): void {
            $orderId = $this->reversibleOrder($event);
            if ($orderId !== null) {
                $this->reverseAll($orderId, false);
            }
        });
    }

    public function onOrderCancelled(StateMachineStateChangeEvent $event): void
    {
        $this->run('order-cancelled', function () use ($event): void {
            $this->reverseAll($event->getTransition()->getEntityId(), true);
        });
    }

    private function reverseAll(string $orderId, bool $refundRedeem): void
    {
        $this->bonus->reverseForOrder($orderId);
        $this->referrals->reverseCommissionForOrder($orderId);
        if ($refundRedeem) {
            $this->bonus->refundRedeemForOrder($orderId);
        }
    }

    /** Bestell-ID, wenn keine andere Zahlung der Bestellung noch „bezahlt“ ist. */
    private function reversibleOrder(StateMachineStateChangeEvent $event): ?string
    {
        $transactionId = $event->getTransition()->getEntityId();
        $orderId = $this->orderIdOfTransaction($transactionId);
        if ($orderId === null) {
            return null;
        }
        $other = $this->db->fetchOne(
            'SELECT COUNT(*) FROM order_transaction ot
             INNER JOIN state_machine_state s ON s.id = ot.state_id
             WHERE ot.order_id = :o AND ot.version_id = :v AND ot.id <> :t AND s.technical_name = \'paid\'',
            [
                'o' => Uuid::fromHexToBytes($orderId),
                'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                't' => Uuid::fromHexToBytes($transactionId),
            ]
        );

        return (int) $other === 0 ? $orderId : null;
    }

    private function orderIdOfTransaction(string $transactionId): ?string
    {
        $id = $this->db->fetchOne(
            'SELECT LOWER(HEX(order_id)) FROM order_transaction WHERE id = :t AND version_id = :v',
            ['t' => Uuid::fromHexToBytes($transactionId), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    private function run(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->error('MaxxpetBonus (' . $what . '): ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
