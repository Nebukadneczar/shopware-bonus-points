<?php declare(strict_types=1);

namespace BonusPoints\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Punktekonto: Buchungen, Kontostand, Level.
 * Alle Zeiten in UTC (wie die Shopware-Datenbank).
 */
class BonusService
{
    public const CFG = 'BonusPoints.config.';

    /** Buchungsarten, die Punkte ausgeben oder zurückgeben. Sie zählen nicht für den Status (das Level). */
    public const SPEND_TYPES = ['redeem', 'redeem_refund'];

    private const TYPE_LABELS = [
        'order' => 'Bestellung',
        'order_reversal' => 'Rückbuchung (Storno/Erstattung)',
        'manual' => 'Gutschrift/Korrektur',
        'redeem' => 'Eingelöst',
        'redeem_refund' => 'Punkte zurück (Storno/Erstattung)',
        'registration' => 'Willkommensbonus',
        'newsletter' => 'Newsletter-Anmeldung',
        'review' => 'Bewertungsprämie',
        'birthday' => 'Geburtstagsbonus',
        'referral' => 'Empfehlungsprovision',
        'referral_reversal' => 'Rückbuchung Empfehlungsprovision',
        'referral_welcome' => 'Willkommensbonus (Empfehlung)',
    ];

    private const DEFAULT_LEVELS = [
        ['name' => 'Bronze', 'min' => 0, 'mult' => 1.0, 'discount' => 0.0],
        ['name' => 'Silber', 'min' => 500, 'mult' => 1.15, 'discount' => 0.0],
        ['name' => 'Gold', 'min' => 2000, 'mult' => 1.3, 'discount' => 0.0],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SystemConfigService $config,
        private readonly MemberService $members,
    ) {
    }

    // ---------------------------------------------------------------- Einstellungen

    public function isActive(): bool
    {
        $v = $this->config->get(self::CFG . 'active');

        return $v === null ? true : (bool) $v;
    }

    public function pointsPerEuro(): float
    {
        $v = $this->config->get(self::CFG . 'pointsPerEuro');

        return is_numeric($v) && (float) $v >= 0 ? (float) $v : 1.0;
    }

    /** Wert eines Punktes in Euro. */
    public function pointValue(): float
    {
        $v = $this->config->get(self::CFG . 'pointValue');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.03;
    }

    public function lockDays(): int
    {
        $v = $this->config->get(self::CFG . 'lockDays');

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 0;
    }

    public function minRedeem(): int
    {
        $v = $this->config->get(self::CFG . 'minRedeem');

        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /** Mindest-Warenwert (Euro), ab dem eingelöst werden darf. 0 = keiner. */
    public function minOrderValue(): float
    {
        $v = $this->config->get(self::CFG . 'minOrderValue');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.0;
    }

    /** Höchstanteil des Warenwerts, der mit Punkten bezahlt werden darf (0 = keine Grenze). */
    public function maxRedeemPercent(): int
    {
        $v = $this->config->get(self::CFG . 'maxRedeemPercent');

        return is_numeric($v) ? max(0, min(100, (int) $v)) : 0;
    }

    /** Dürfen Stufenrabatt und Punkte-Einlösung zusammen angewendet werden? */
    public function combineWithLevelDiscount(): bool
    {
        $v = $this->config->get(self::CFG . 'combineWithLevelDiscount');

        return $v === null ? true : (bool) $v;
    }

    /** "lifetime" (alles bisher), "rolling12" (letzte 12 Monate) oder "off" (keine Stufen). */
    public function levelModel(): string
    {
        $v = (string) $this->config->get(self::CFG . 'levelModel');

        return \in_array($v, ['rolling12', 'off'], true) ? $v : 'lifetime';
    }

    public function levelsEnabled(): bool
    {
        return $this->levelModel() !== 'off';
    }

    /** Punkte für einmalige Aktionen (registration, newsletter, review, birthday). 0 = aus. */
    public function actionPoints(string $key): int
    {
        $v = $this->config->get(self::CFG . 'points' . ucfirst($key));

        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /**
     * @return list<array{name:string,min:int,mult:float,discount:float}> aufsteigend nach Schwelle
     */
    public function levels(): array
    {
        if (!$this->levelsEnabled()) {
            return [['name' => '', 'min' => 0, 'mult' => 1.0, 'discount' => 0.0]];
        }
        $levels = [];
        $text = (string) $this->config->get(self::CFG . 'levelsText');
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $p = array_map('trim', explode(';', $line));
            if (\count($p) < 2 || $p[0] === '') {
                continue;
            }
            $mult = isset($p[2]) && $p[2] !== '' ? (float) str_replace(',', '.', $p[2]) : 1.0;
            $disc = isset($p[3]) && $p[3] !== '' ? (float) str_replace(',', '.', $p[3]) : 0.0;
            $levels[] = [
                'name' => $p[0],
                'min' => max(0, (int) preg_replace('/[^0-9]/', '', $p[1])),
                'mult' => $mult > 0 ? $mult : 1.0,
                'discount' => max(0.0, min(100.0, $disc)),
            ];
        }
        if ($levels === []) {
            $levels = self::DEFAULT_LEVELS;
        }
        usort($levels, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        $levels[0]['min'] = 0; // das unterste Level gilt immer

        return $levels;
    }

    /**
     * @return array{level:array{name:string,min:int,mult:float,discount:float},next:?array{name:string,min:int,mult:float,discount:float},missing:int,progress:int}
     */
    public function levelFor(int $statusPoints): array
    {
        $levels = $this->levels();
        $current = $levels[0];
        $next = null;
        foreach ($levels as $l) {
            if ($statusPoints >= $l['min']) {
                $current = $l;
            } elseif ($next === null) {
                $next = $l;
            }
        }
        $missing = $next !== null ? $next['min'] - $statusPoints : 0;
        $progress = 100;
        if ($next !== null) {
            $span = max(1, $next['min'] - $current['min']);
            $progress = (int) max(0, min(100, floor(($statusPoints - $current['min']) / $span * 100)));
        }

        return ['level' => $current, 'next' => $next, 'missing' => $missing, 'progress' => $progress];
    }

    // ---------------------------------------------------------------- Konto

    /**
     * @return array{available:int,pending:int,status:int,levelsOn:bool,level:array{name:string,min:int,mult:float,discount:float},next:?array{name:string,min:int,mult:float,discount:float},missing:int,progress:int}
     */
    public function account(string $customerId): array
    {
        $spend = "'" . implode("','", self::SPEND_TYPES) . "'";
        $rolling = $this->levelModel() === 'rolling12';
        $params = [
            'now1' => $this->now(),
            'now2' => $this->now(),
            'now3' => $this->now(),
            'c' => Uuid::fromHexToBytes($customerId),
        ];
        $since = '';
        if ($rolling) {
            $since = ' AND available_at > :since';
            $params['since'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-12 months')->format('Y-m-d H:i:s.v');
        }

        $row = $this->db->fetchAssociative(
            'SELECT
                COALESCE(SUM(CASE WHEN available_at <= :now1 THEN points ELSE 0 END), 0) AS available,
                COALESCE(SUM(CASE WHEN available_at > :now2 THEN points ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN available_at <= :now3 AND type NOT IN (' . $spend . ')' . $since . ' THEN points ELSE 0 END), 0) AS status
             FROM bonus_points_entry WHERE customer_id = :c',
            $params
        ) ?: ['available' => 0, 'pending' => 0, 'status' => 0];

        $status = max(0, (int) $row['status']);

        return [
            'available' => (int) $row['available'],
            'pending' => (int) $row['pending'],
            'status' => $status,
            'levelsOn' => $this->levelsEnabled(),
        ] + $this->levelFor($status);
    }

    /**
     * @return list<array{type:string,label:string,points:int,orderNumber:?string,reason:?string,availableAt:string,createdAt:string,pending:bool}>
     */
    public function entries(string $customerId, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT type, points, order_number, LOWER(HEX(order_id)) AS order_id, reason, available_at, created_at
             FROM bonus_points_entry WHERE customer_id = :c
             ORDER BY created_at DESC, available_at DESC LIMIT ' . max(1, min(500, $limit)),
            ['c' => Uuid::fromHexToBytes($customerId)]
        );
        $now = $this->now();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'type' => (string) $r['type'],
                'label' => self::TYPE_LABELS[$r['type']] ?? (string) $r['type'],
                'points' => (int) $r['points'],
                'orderNumber' => $r['order_number'] !== null ? (string) $r['order_number'] : null,
                'orderId' => !empty($r['order_id']) ? (string) $r['order_id'] : null,
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'availableAt' => (string) $r['available_at'],
                'createdAt' => (string) $r['created_at'],
                'pending' => (string) $r['available_at'] > $now,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- Bestellungen

    /**
     * Warenwert (Euro, ohne Versand) und Kunde einer Bestellung.
     *
     * @return array{orderNumber:string,customerId:?string,guest:bool,euro:float}|null
     */
    public function orderBase(string $orderId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT o.order_number, o.amount_total, o.shipping_costs, o.currency_factor,
                    LOWER(HEX(oc.customer_id)) AS customer_id, c.guest
             FROM `order` o
             INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
             LEFT JOIN customer c ON c.id = oc.customer_id
             WHERE o.id = :id AND o.version_id = :v',
            ['id' => Uuid::fromHexToBytes($orderId), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if (!$row) {
            return null;
        }
        $shipping = 0.0;
        $sc = json_decode((string) ($row['shipping_costs'] ?? ''), true);
        if (\is_array($sc) && isset($sc['totalPrice']) && is_numeric($sc['totalPrice'])) {
            $shipping = (float) $sc['totalPrice'];
        }
        $factor = (float) $row['currency_factor'] > 0 ? (float) $row['currency_factor'] : 1.0;

        return [
            'orderNumber' => (string) $row['order_number'],
            'customerId' => !empty($row['customer_id']) ? (string) $row['customer_id'] : null,
            'guest' => (int) $row['guest'] === 1,
            'euro' => ((float) $row['amount_total'] - $shipping) / $factor,
        ];
    }

    /**
     * Punkte für eine bezahlte Bestellung gutschreiben (einmal je Bestellung).
     *
     * @param \DateTimeImmutable|null $base Ausgangszeit für die Sperrfrist (Standard: jetzt; beim Nachtragen das Bestelldatum)
     *
     * @return string "ok:<punkte>" oder "skip:<grund>"
     */
    public function awardForOrder(string $orderId, ?\DateTimeImmutable $base = null): string
    {
        $o = $this->orderBase($orderId);
        if ($o === null) {
            return 'skip:Bestellung nicht gefunden';
        }
        if ($o['customerId'] === null || $o['guest']) {
            return 'skip:Gastbestellung (kein Kundenkonto)';
        }
        if (!$this->members->isParticipant($o['customerId'])) {
            return 'skip:keine Teilnahme am Programm';
        }
        if ($o['euro'] <= 0) {
            return 'skip:kein Warenwert';
        }

        $acc = $this->account($o['customerId']);
        $mult = $acc['level']['mult'];
        $points = (int) floor($o['euro'] * $this->pointsPerEuro() * $mult);
        if ($points <= 0) {
            return 'skip:0 Punkte';
        }

        // Wurde die Bestellung schon abgerechnet oder zurückgebucht, nichts mehr gutschreiben
        $already = $this->db->fetchOne(
            'SELECT COUNT(*) FROM bonus_points_entry WHERE order_id = :o AND type IN (\'order\', \'order_reversal\')',
            ['o' => Uuid::fromHexToBytes($orderId)]
        );
        if ((int) $already > 0) {
            return 'skip:bereits abgerechnet';
        }

        $available = ($base ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . $this->lockDays() . ' days');

        $reason = sprintf('Bestellung %s', $o['orderNumber']);
        if ($acc['levelsOn'] && $mult > 1.0) {
            $reason .= sprintf(' (Level %s ×%s)', $acc['level']['name'], rtrim(rtrim(number_format($mult, 2, ',', ''), '0'), ','));
        }

        return $this->addEntry($o['customerId'], 'order', $points, $reason, null, $orderId, $o['orderNumber'], $available)
            ? 'ok:' . $points
            : 'skip:bereits abgerechnet';
    }

    /**
     * Gutschrift einer Bestellung zurückbuchen (Storno/Erstattung). Wirkt so, dass sich beide Buchungen ausgleichen.
     */
    public function reverseForOrder(string $orderId): bool
    {
        $e = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(customer_id)) AS customer_id, points, order_number, available_at
             FROM bonus_points_entry WHERE order_id = :o AND type = \'order\'',
            ['o' => Uuid::fromHexToBytes($orderId)]
        );
        if (!$e) {
            return false;
        }
        $orig = new \DateTimeImmutable((string) $e['available_at'], new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->addEntry(
            $e['customer_id'],
            'order_reversal',
            -((int) $e['points']),
            sprintf('Rückbuchung Bestellung %s (Storno/Erstattung)', $e['order_number']),
            null,
            $orderId,
            (string) $e['order_number'],
            $orig > $now ? $orig : $now
        );
    }

    /**
     * Beim Bestellen: eingelöste Punkte aus der Bestellposition abbuchen (einmal je Bestellung).
     * Die Punktzahl steht im Payload der Position; fehlt sie, wird sie aus dem Rabattbetrag errechnet.
     */
    public function redeemFromOrder(string $orderId): bool
    {
        $row = $this->db->fetchAssociative(
            'SELECT li.payload, li.total_price, o.order_number, o.currency_factor,
                    LOWER(HEX(oc.customer_id)) AS customer_id, c.guest
             FROM order_line_item li
             INNER JOIN `order` o ON o.id = li.order_id AND o.version_id = li.order_version_id
             INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
             LEFT JOIN customer c ON c.id = oc.customer_id
             WHERE li.order_id = :o AND li.order_version_id = :v AND li.type = \'bonus_points\' LIMIT 1',
            ['o' => Uuid::fromHexToBytes($orderId), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if (!$row || empty($row['customer_id']) || (int) $row['guest'] === 1) {
            return false;
        }
        $payload = json_decode((string) $row['payload'], true);
        $points = \is_array($payload) ? (int) ($payload['pointsRedeemed'] ?? 0) : 0;
        if ($points <= 0) {
            $factor = (float) $row['currency_factor'] > 0 ? (float) $row['currency_factor'] : 1.0;
            $points = (int) round(abs((float) $row['total_price']) / ($this->pointValue() * $factor));
        }
        if ($points <= 0) {
            return false;
        }

        return $this->addEntry(
            $row['customer_id'],
            'redeem',
            -$points,
            sprintf('Eingelöst bei Bestellung %s', $row['order_number']),
            null,
            $orderId,
            (string) $row['order_number'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * Eingelöste Punkte zurückgeben, wenn die Bestellung storniert oder erstattet wird.
     */
    public function refundRedeemForOrder(string $orderId): bool
    {
        $e = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(customer_id)) AS customer_id, points, order_number
             FROM bonus_points_entry WHERE order_id = :o AND type = \'redeem\'',
            ['o' => Uuid::fromHexToBytes($orderId)]
        );
        if (!$e) {
            return false;
        }

        return $this->addEntry(
            $e['customer_id'],
            'redeem_refund',
            -((int) $e['points']),
            sprintf('Punkte zurück: Bestellung %s storniert/erstattet', $e['order_number']),
            null,
            $orderId,
            (string) $e['order_number'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    // ---------------------------------------------------------------- Buchen

    /**
     * Manuelle Buchung (positiv oder negativ), sofort wirksam.
     */
    public function addManual(string $customerId, int $points, string $reason): void
    {
        $this->addEntry($customerId, 'manual', $points, mb_substr($reason, 0, 255), null, null, null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Einmalige Aktion buchen (z. B. Registrierung). `$ref` macht sie eindeutig je Kunde und Art.
     */
    public function addAction(string $customerId, string $type, int $points, string $reason, string $ref): bool
    {
        if ($points <= 0 || !$this->isActive() || !$this->members->isParticipant($customerId)) {
            return false;
        }

        return $this->addEntry($customerId, $type, $points, $reason, $ref, null, null, $this->availableAfterLock());
    }

    /**
     * Allgemeine Buchung. Gibt false zurück, wenn die Buchung schon existiert (Eindeutigkeit) oder nichts eingefügt wurde.
     */
    public function addEntry(string $customerId, string $type, int $points, ?string $reason, ?string $ref, ?string $orderId, ?string $orderNumber, \DateTimeImmutable $availableAt): bool
    {
        return (int) $this->db->executeStatement(
            'INSERT IGNORE INTO bonus_points_entry
                (id, customer_id, type, points, order_id, order_number, ref, reason, available_at, created_at)
             VALUES (:id, :c, :t, :p, :o, :n, :ref, :r, :a, :now)',
            [
                'id' => Uuid::randomBytes(),
                'c' => Uuid::fromHexToBytes($customerId),
                't' => $type,
                'p' => $points,
                'o' => $orderId !== null ? Uuid::fromHexToBytes($orderId) : null,
                'n' => $orderNumber,
                'ref' => $ref !== null ? mb_substr($ref, 0, 64) : null,
                'r' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'a' => $availableAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
                'now' => $this->now(),
            ]
        ) > 0;
    }

    public function availableAfterLock(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $this->lockDays() . ' days');
    }

    /**
     * @return list<array{id:string,name:string,email:string}>
     */
    public function findCustomers(string $email): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, first_name, last_name, email FROM customer WHERE LOWER(email) = LOWER(:e) AND guest = 0',
            ['e' => trim($email)]
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'email' => (string) $r['email'],
        ], $rows);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
