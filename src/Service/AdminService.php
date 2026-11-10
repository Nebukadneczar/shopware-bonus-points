<?php declare(strict_types=1);

namespace BonusPoints\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Daten für die Verwaltung (Admin-Modul und Befehle): Kennzahlen, Kundenliste, Detail, Import.
 */
class AdminService
{
    public function __construct(
        private readonly Connection $db,
        private readonly BonusService $bonus,
        private readonly MemberService $members,
        private readonly ReferralService $referrals,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT
                COALESCE(SUM(CASE WHEN type NOT IN (\'redeem\', \'redeem_refund\') AND points > 0 THEN points ELSE 0 END), 0) AS issued,
                COALESCE(SUM(CASE WHEN type NOT IN (\'redeem\', \'redeem_refund\') AND points < 0 THEN -points ELSE 0 END), 0) AS reversed,
                COALESCE(SUM(CASE WHEN type IN (\'redeem\', \'redeem_refund\') THEN -points ELSE 0 END), 0) AS redeemed,
                COALESCE(SUM(points), 0) AS outstanding,
                COUNT(DISTINCT customer_id) AS customers
             FROM bonus_points_entry'
        ) ?: [];
        $unit = $this->bonus->pointValue();
        $outstanding = (int) ($row['outstanding'] ?? 0);

        return [
            'issued' => (int) ($row['issued'] ?? 0),
            'reversed' => (int) ($row['reversed'] ?? 0),
            'redeemed' => (int) ($row['redeemed'] ?? 0),
            'outstanding' => $outstanding,
            'outstandingEuro' => round($outstanding * $unit, 2),
            'redeemedEuro' => round((int) ($row['redeemed'] ?? 0) * $unit, 2),
            'customers' => (int) ($row['customers'] ?? 0),
            'referrals' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM bonus_points_referral'),
            'blocked' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM bonus_points_member WHERE status = \'blocked\''),
            'pointValue' => $unit,
        ];
    }

    private const SORTS = [
        'available' => 'available',
        'pending' => 'pending',
        'name' => 'c.last_name, c.first_name',
        'email' => 'c.email',
    ];

    /**
     * Gemeinsamer Teil der Kundenabfrage (Auswahl, Filter, Suche).
     *
     * @return array{0:string,1:string,2:array<string,mixed>} [SELECT-Teil, WHERE/GROUP/HAVING-Teil, Parameter]
     */
    private function customerQuery(string $search, string $filter): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $where = 'c.guest = 0';
        $params = ['now1' => $now, 'now2' => $now];
        $having = '';

        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (c.email LIKE :q OR CONCAT(c.first_name, \' \', c.last_name) LIKE :q2 OR c.customer_number LIKE :q3)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params['q'] = $params['q2'] = $params['q3'] = $like;
        } elseif ($filter !== 'all') {
            $where .= ' AND (m.customer_id IS NOT NULL OR EXISTS (SELECT 1 FROM bonus_points_entry x WHERE x.customer_id = c.id))';
        }
        if ($filter === 'blocked') {
            $where .= ' AND m.status = \'blocked\'';
        } elseif ($filter === 'referrers') {
            $where .= ' AND EXISTS (SELECT 1 FROM bonus_points_referral r WHERE r.referrer_id = c.id)';
        } elseif ($filter === 'positive') {
            $having = ' HAVING available > 0';
        }

        $select = 'SELECT LOWER(HEX(c.id)) AS id, c.first_name, c.last_name, c.email, c.customer_number, m.status AS member_status,
                    COALESCE(SUM(CASE WHEN e.available_at <= :now1 THEN e.points ELSE 0 END), 0) AS available,
                    COALESCE(SUM(CASE WHEN e.available_at > :now2 THEN e.points ELSE 0 END), 0) AS pending
             FROM customer c
             LEFT JOIN bonus_points_member m ON m.customer_id = c.id
             LEFT JOIN bonus_points_entry e ON e.customer_id = c.id';
        $tail = ' WHERE ' . $where . '
             GROUP BY c.id, c.first_name, c.last_name, c.email, c.customer_number, m.status' . $having;

        return [$select, $tail, $params];
    }

    /**
     * @return array{total:int,items:list<array<string,mixed>>}
     */
    public function customers(string $search, int $page, int $limit, string $filter = 'active', string $sort = 'available', string $dir = 'desc'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, ($page - 1) * $limit);
        $filter = \in_array($filter, ['active', 'positive', 'blocked', 'referrers', 'all'], true) ? $filter : 'active';
        $order = (self::SORTS[$sort] ?? 'available') . (strtolower($dir) === 'asc' ? ' ASC' : ' DESC');
        if (($sort ?: 'available') !== 'name') {
            $order .= ', c.last_name ASC';
        }

        [$select, $tail, $params] = $this->customerQuery($search, $filter);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM (' . $select . $tail . ') t', $params);
        $rows = $this->db->fetchAllAssociative(
            $select . $tail . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (string) $r['id'],
                'name' => trim($r['first_name'] . ' ' . $r['last_name']),
                'email' => (string) $r['email'],
                'customerNumber' => (string) $r['customer_number'],
                'available' => (int) $r['available'],
                'pending' => (int) $r['pending'],
                'blocked' => $r['member_status'] === 'blocked',
                'member' => $r['member_status'] === 'active',
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Alle Kunden mit Punkten als CSV (Semikolon, UTF-8 mit BOM, für Excel), z. B. für die Buchhaltung.
     */
    public function exportCsv(): string
    {
        [$select, $tail, $params] = $this->customerQuery('', 'active');
        $rows = $this->db->fetchAllAssociative($select . $tail . ' ORDER BY c.last_name ASC LIMIT 20000', $params);
        $unit = $this->bonus->pointValue();
        $out = "\xEF\xBB\xBF" . "Kundennummer;Name;E-Mail;Verfügbar;Ausstehend;Wert in Euro;Status\r\n";
        foreach ($rows as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $state = $r['member_status'] === 'blocked' ? 'gesperrt' : ($r['member_status'] === 'active' ? 'Teilnehmer' : '');
            $cells = [
                $r['customer_number'],
                $name,
                $r['email'],
                (string) (int) $r['available'],
                (string) (int) $r['pending'],
                number_format(((int) $r['available'] + (int) $r['pending']) * $unit, 2, ',', ''),
                $state,
            ];
            // Schutz vor Formel-Einschleusung in Excel
            $cells = array_map(static fn (string $v): string => preg_match('/^[=+\-@]/', $v) && !preg_match('/^-?\d+([,.]\d+)?$/', $v) ? "'" . $v : $v, $cells);
            $out .= implode(';', array_map(static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"', $cells)) . "\r\n";
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function customer(string $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT first_name, last_name, email, customer_number, guest FROM customer WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );
        if (!$row || (int) $row['guest'] === 1) {
            return null;
        }
        $state = $this->members->state($id);

        return [
            'id' => $id,
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'email' => (string) $row['email'],
            'customerNumber' => (string) $row['customer_number'],
            'state' => $state,
            'participant' => $this->members->isParticipant($id),
            'account' => $this->bonus->account($id),
            'entries' => $this->bonus->entries($id, 100),
            'referral' => $this->referrals->stats($id),
            'pointValue' => $this->bonus->pointValue(),
        ];
    }

    /**
     * Bezahlte Bestellungen ohne Punktebuchung finden und auf Wunsch nachbuchen.
     *
     * @return array{count:int,booked:int,lines:list<string>}
     */
    public function importOrders(string $since, bool $apply): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT LOWER(HEX(o.id)) AS id, o.order_number, o.order_date_time
             FROM `order` o
             INNER JOIN state_machine_state os ON os.id = o.state_id
             WHERE o.version_id = :v AND os.technical_name <> \'cancelled\' AND o.order_date_time >= :seit
               AND EXISTS (SELECT 1 FROM order_transaction ot
                           INNER JOIN state_machine_state ts ON ts.id = ot.state_id
                           WHERE ot.order_id = o.id AND ot.version_id = o.version_id AND ts.technical_name = \'paid\')
               AND NOT EXISTS (SELECT 1 FROM bonus_points_entry e WHERE e.order_id = o.id AND e.type IN (\'order\', \'order_reversal\'))
             ORDER BY o.order_date_time',
            ['v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'seit' => $since . ' 00:00:00']
        );
        $lines = [];
        $booked = 0;
        foreach ($rows as $r) {
            if (!$apply) {
                $lines[] = $r['order_number'] . '  ' . substr((string) $r['order_date_time'], 0, 10);
                continue;
            }
            $base = new \DateTimeImmutable((string) $r['order_date_time'], new \DateTimeZone('UTC'));
            $res = $this->bonus->awardForOrder((string) $r['id'], $base);
            $lines[] = $r['order_number'] . ': ' . $res;
            if (str_starts_with($res, 'ok:')) {
                ++$booked;
            }
        }

        return ['count' => \count($rows), 'booked' => $booked, 'lines' => $lines];
    }
}
