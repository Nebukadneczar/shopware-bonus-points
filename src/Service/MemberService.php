<?php declare(strict_types=1);

namespace MaxxpetBonus\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Teilnahme am Bonusprogramm: Kundengruppen, Selbst-Anmeldung (Opt-in), Sperre, Empfehlungscode.
 */
class MemberService
{
    private const CFG = 'MaxxpetBonus.config.';
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var array<string,bool> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SystemConfigService $config,
    ) {
    }

    /** "auto": jeder berechtigte Kunde nimmt teil. "optin": Kunde muss beitreten. */
    public function enrollment(): string
    {
        return $this->config->get(self::CFG . 'enrollment') === 'optin' ? 'optin' : 'auto';
    }

    /** @return list<string> Kundengruppen-IDs (hex); leer = alle */
    public function allowedGroupIds(): array
    {
        $v = $this->config->get(self::CFG . 'customerGroupIds');
        if (!\is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($x): string => strtolower((string) $x), $v), static fn (string $x): bool => $x !== ''));
    }

    /**
     * Darf der Kunde (mit Konto) am Programm teilnehmen und nimmt er teil?
     */
    public function isParticipant(string $customerId): bool
    {
        if (isset($this->cache[$customerId])) {
            return $this->cache[$customerId];
        }
        $s = $this->state($customerId);

        return $this->cache[$customerId] = ($s['eligible'] && ($this->enrollment() === 'auto' || $s['member']));
    }

    /**
     * @return array{eligible:bool,member:bool,blocked:bool,groupAllowed:bool}
     */
    public function state(string $customerId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(c.customer_group_id)) AS g, c.guest, m.status
             FROM customer c LEFT JOIN maxxpet_bonus_member m ON m.customer_id = c.id
             WHERE c.id = :c',
            ['c' => Uuid::fromHexToBytes($customerId)]
        );
        if (!$row || (int) $row['guest'] === 1) {
            return ['eligible' => false, 'member' => false, 'blocked' => false, 'groupAllowed' => false];
        }
        $blocked = $row['status'] === 'blocked';
        $groups = $this->allowedGroupIds();
        $groupAllowed = $groups === [] || \in_array((string) $row['g'], $groups, true);

        return [
            'eligible' => !$blocked && $groupAllowed,
            'member' => $row['status'] === 'active',
            'blocked' => $blocked,
            'groupAllowed' => $groupAllowed,
        ];
    }

    /** Kunde tritt dem Programm bei (gesperrte Kunden bleiben gesperrt). */
    public function join(string $customerId, string $source): void
    {
        $this->db->executeStatement(
            'INSERT INTO maxxpet_bonus_member (customer_id, status, source, joined_at) VALUES (:c, \'active\', :s, :n)
             ON DUPLICATE KEY UPDATE status = IF(status = \'blocked\', \'blocked\', \'active\')',
            ['c' => Uuid::fromHexToBytes($customerId), 's' => $source, 'n' => $this->now()]
        );
        unset($this->cache[$customerId]);
    }

    public function block(string $customerId): void
    {
        $this->db->executeStatement(
            'INSERT INTO maxxpet_bonus_member (customer_id, status, source, joined_at) VALUES (:c, \'blocked\', \'admin\', :n)
             ON DUPLICATE KEY UPDATE status = \'blocked\'',
            ['c' => Uuid::fromHexToBytes($customerId), 'n' => $this->now()]
        );
        unset($this->cache[$customerId]);
    }

    public function unblock(string $customerId): void
    {
        $this->db->executeStatement(
            'UPDATE maxxpet_bonus_member SET status = \'active\' WHERE customer_id = :c AND status = \'blocked\'',
            ['c' => Uuid::fromHexToBytes($customerId)]
        );
        unset($this->cache[$customerId]);
    }

    /** Persönlicher Empfehlungscode (wird bei Bedarf erzeugt). */
    public function referralCode(string $customerId): string
    {
        $bytes = Uuid::fromHexToBytes($customerId);
        $code = $this->db->fetchOne('SELECT referral_code FROM maxxpet_bonus_member WHERE customer_id = :c', ['c' => $bytes]);
        if (\is_string($code) && $code !== '') {
            return $code;
        }
        for ($i = 0; $i < 8; ++$i) {
            $candidate = '';
            for ($j = 0; $j < 8; ++$j) {
                $candidate .= self::CODE_CHARS[random_int(0, \strlen(self::CODE_CHARS) - 1)];
            }
            $taken = $this->db->fetchOne('SELECT COUNT(*) FROM maxxpet_bonus_member WHERE referral_code = :x', ['x' => $candidate]);
            if ((int) $taken > 0) {
                continue;
            }
            $this->db->executeStatement(
                'INSERT INTO maxxpet_bonus_member (customer_id, status, source, joined_at, referral_code) VALUES (:c, \'active\', \'code\', :n, :x)
                 ON DUPLICATE KEY UPDATE referral_code = COALESCE(referral_code, VALUES(referral_code))',
                ['c' => $bytes, 'n' => $this->now(), 'x' => $candidate]
            );
            $stored = $this->db->fetchOne('SELECT referral_code FROM maxxpet_bonus_member WHERE customer_id = :c', ['c' => $bytes]);

            return (string) $stored;
        }
        throw new \RuntimeException('Empfehlungscode konnte nicht erzeugt werden');
    }

    /** @return string|null Kunden-ID des Werbenden zu einem Code */
    public function customerByCode(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $code)) {
            return null;
        }
        $id = $this->db->fetchOne(
            'SELECT LOWER(HEX(customer_id)) FROM maxxpet_bonus_member WHERE referral_code = :x AND status = \'active\'',
            ['x' => $code]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
