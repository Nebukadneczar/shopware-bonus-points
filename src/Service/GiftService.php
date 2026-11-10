<?php declare(strict_types=1);

namespace MaxxpetBonus\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Gratisprodukte ab Warenwert und/oder Level, automatisch oder zur Auswahl.
 * Regeln stehen in der Konfiguration, je Zeile: Artikelnummer;Bezeichnung;Mindestwarenwert;Mindestlevel;auto|wahl
 */
class GiftService
{
    private const CFG = 'MaxxpetBonus.config.';

    /** @var array<string,bool> */
    private array $exists = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SystemConfigService $config,
        private readonly BonusService $bonus,
    ) {
    }

    /**
     * @return list<array{sku:string,label:string,min:float,level:int,mode:string}>
     */
    public function rules(): array
    {
        $levels = $this->bonus->levels();
        $names = array_map(static fn (array $l): string => mb_strtolower($l['name']), $levels);
        $out = [];
        $text = (string) $this->config->get(self::CFG . 'giftRules');
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $p = array_map('trim', explode(';', $line));
            if (\count($p) < 2 || $p[0] === '' || $p[1] === '') {
                continue;
            }
            $levelIdx = 0;
            if (isset($p[3]) && $p[3] !== '') {
                $found = array_search(mb_strtolower($p[3]), $names, true);
                if ($found === false) {
                    continue; // unbekanntes Level: Regel ignorieren statt für alle freizugeben
                }
                $levelIdx = (int) $found;
            }
            $mode = isset($p[4]) && mb_strtolower($p[4]) === 'wahl' ? 'wahl' : 'auto';
            $out[] = [
                'sku' => $p[0],
                'label' => $p[1],
                'min' => isset($p[2]) && $p[2] !== '' ? max(0.0, (float) str_replace(',', '.', $p[2])) : 0.0,
                'level' => $levelIdx,
                'mode' => $mode,
            ];
        }

        return $out;
    }

    /**
     * Regeln, die bei diesem Warenwert (Euro) und Level-Index gelten und deren Artikel existiert.
     *
     * @return list<array{sku:string,label:string,min:float,level:int,mode:string}>
     */
    public function eligible(float $goodsValueEuro, int $levelIndex): array
    {
        $out = [];
        foreach ($this->rules() as $r) {
            if ($goodsValueEuro + 0.0001 < $r['min'] || $levelIndex < $r['level']) {
                continue;
            }
            if (!$this->productExists($r['sku'])) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Nächstes Gratisprodukt, das nur noch am Warenwert scheitert (für den Hinweis „Noch X € bis zum Geschenk“).
     *
     * @return array{sku:string,label:string,min:float,level:int,mode:string}|null
     */
    public function nextByValue(float $goodsValueEuro, int $levelIndex): ?array
    {
        $best = null;
        foreach ($this->rules() as $r) {
            if ($r['min'] <= $goodsValueEuro || $levelIndex < $r['level'] || !$this->productExists($r['sku'])) {
                continue;
            }
            if ($best === null || $r['min'] < $best['min']) {
                $best = $r;
            }
        }

        return $best;
    }

    public function levelIndex(string $levelName): int
    {
        foreach ($this->bonus->levels() as $i => $l) {
            if (mb_strtolower($l['name']) === mb_strtolower($levelName)) {
                return $i;
            }
        }

        return 0;
    }

    private function productExists(string $sku): bool
    {
        if (!isset($this->exists[$sku])) {
            $id = $this->db->fetchOne(
                'SELECT 1 FROM product WHERE product_number = :n AND version_id = :v LIMIT 1',
                ['n' => $sku, 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            $this->exists[$sku] = $id !== false;
        }

        return $this->exists[$sku];
    }
}
