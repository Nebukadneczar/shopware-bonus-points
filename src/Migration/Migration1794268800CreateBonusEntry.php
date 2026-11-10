<?php declare(strict_types=1);

namespace MaxxpetBonus\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Punkte-Journal: jede Gutschrift und Abbuchung ist eine eigene Zeile, der Kontostand ist die Summe.
 * Es wird nie eine Zeile geändert; Korrekturen sind neue Zeilen.
 */
class Migration1794268800CreateBonusEntry extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794268800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `maxxpet_bonus_entry` (
    `id` BINARY(16) NOT NULL,
    `customer_id` BINARY(16) NOT NULL,
    `type` VARCHAR(32) NOT NULL,
    `points` INT NOT NULL,
    `order_id` BINARY(16) NULL,
    `order_number` VARCHAR(64) NULL,
    `reason` VARCHAR(255) NULL,
    `available_at` DATETIME(3) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.maxxpet_bonus_entry.order_type` (`order_id`, `type`),
    KEY `idx.maxxpet_bonus_entry.customer` (`customer_id`, `available_at`),
    CONSTRAINT `fk.maxxpet_bonus_entry.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
