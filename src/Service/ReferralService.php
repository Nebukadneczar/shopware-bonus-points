<?php declare(strict_types=1);

namespace Bonus\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Empfehlungssystem: Wer einen Kunden wirbt, bekommt für alle seine Bestellungen eine Provision in Punkten
 * (lebenslang, solange beide am Programm teilnehmen). Der Geworbene kann einen Willkommensbonus erhalten.
 */
class ReferralService
{
    private const CFG = 'Bonus.config.';

    public function __construct(
        private readonly Connection $db,
        private readonly SystemConfigService $config,
        private readonly BonusService $bonus,
        private readonly MemberService $members,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get(self::CFG . 'referralEnabled');
    }

    /** Provision: Punkte je 1 € Warenwert der Bestellungen des Geworbenen. */
    public function pointsPerEuro(): float
    {
        $v = $this->config->get(self::CFG . 'referralPointsPerEuro');

        return is_numeric($v) && (float) $v >= 0 ? (float) $v : 0.5;
    }

    public function welcomePoints(): int
    {
        $v = $this->config->get(self::CFG . 'referralWelcome');

        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /**
     * Neuen Kunden einem Werbenden zuordnen. Nur bei der Registrierung aufrufen.
     */
    public function register(string $newCustomerId, string $code): bool
    {
        if (!$this->enabled() || !$this->bonus->isActive()) {
            return false;
        }
        $referrerId = $this->members->customerByCode($code);
        if ($referrerId === null || $referrerId === $newCustomerId || !$this->members->isParticipant($referrerId)) {
            return false;
        }
        // Selbstwerbung über ein zweites Konto mit derselben E-Mail-Adresse verhindern
        $same = $this->db->fetchOne(
            'SELECT COUNT(*) FROM customer a INNER JOIN customer b ON LOWER(a.email) = LOWER(b.email)
             WHERE a.id = :a AND b.id = :b',
            ['a' => Uuid::fromHexToBytes($newCustomerId), 'b' => Uuid::fromHexToBytes($referrerId)]
        );
        if ((int) $same > 0) {
            return false;
        }

        $n = $this->db->executeStatement(
            'INSERT IGNORE INTO bonus_referral (customer_id, referrer_id, created_at) VALUES (:c, :r, :n)',
            [
                'c' => Uuid::fromHexToBytes($newCustomerId),
                'r' => Uuid::fromHexToBytes($referrerId),
                'n' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            ]
        );
        if ($n > 0 && $this->welcomePoints() > 0) {
            $this->bonus->addAction($newCustomerId, 'referral_welcome', $this->welcomePoints(), 'Willkommensbonus durch Empfehlung', 'welcome');
        }

        return $n > 0;
    }

    /** Provision für den Werbenden, wenn der Geworbene eine bezahlte Bestellung hat. */
    public function commissionForOrder(string $orderId): void
    {
        if (!$this->enabled() || !$this->bonus->isActive()) {
            return;
        }
        $o = $this->bonus->orderBase($orderId);
        if ($o === null || $o['customerId'] === null || $o['guest'] || $o['euro'] <= 0) {
            return;
        }
        $referrer = $this->db->fetchOne(
            'SELECT LOWER(HEX(referrer_id)) FROM bonus_referral WHERE customer_id = :c',
            ['c' => Uuid::fromHexToBytes($o['customerId'])]
        );
        if (!\is_string($referrer) || $referrer === '' || !$this->members->isParticipant($referrer)) {
            return;
        }
        $points = (int) floor($o['euro'] * $this->pointsPerEuro());
        if ($points <= 0) {
            return;
        }
        $this->bonus->addEntry(
            $referrer,
            'referral',
            $points,
            sprintf('Empfehlungsprovision, Bestellung %s', $o['orderNumber']),
            null,
            $orderId,
            $o['orderNumber'],
            $this->bonus->availableAfterLock()
        );
    }

    public function reverseCommissionForOrder(string $orderId): void
    {
        $e = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(customer_id)) AS customer_id, points, order_number, available_at
             FROM bonus_entry WHERE order_id = :o AND type = \'referral\'',
            ['o' => Uuid::fromHexToBytes($orderId)]
        );
        if (!$e) {
            return;
        }
        $orig = new \DateTimeImmutable((string) $e['available_at'], new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->bonus->addEntry(
            $e['customer_id'],
            'referral_reversal',
            -((int) $e['points']),
            sprintf('Rückbuchung Empfehlungsprovision, Bestellung %s', $e['order_number']),
            null,
            $orderId,
            (string) $e['order_number'],
            $orig > $now ? $orig : $now
        );
    }

    /**
     * @return array{count:int,points:int}
     */
    public function stats(string $referrerId): array
    {
        $bytes = Uuid::fromHexToBytes($referrerId);
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM bonus_referral WHERE referrer_id = :r', ['r' => $bytes]);
        $points = (int) $this->db->fetchOne(
            'SELECT COALESCE(SUM(points), 0) FROM bonus_entry WHERE customer_id = :r AND type IN (\'referral\', \'referral_reversal\')',
            ['r' => $bytes]
        );

        return ['count' => $count, 'points' => $points];
    }
}
