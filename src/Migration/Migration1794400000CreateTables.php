<?php declare(strict_types=1);

namespace Bonus\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Legt die Tabellen an:
 *  - bonus_entry:    Punkte-Journal (jede Buchung eine Zeile, der Kontostand ist die Summe)
 *  - bonus_member:   Teilnahme, Sperre, Empfehlungscode
 *  - bonus_referral: wer wurde von wem geworben
 */
class Migration1794400000CreateTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794400000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `bonus_entry` (
    `id` BINARY(16) NOT NULL,
    `customer_id` BINARY(16) NOT NULL,
    `type` VARCHAR(32) NOT NULL,
    `points` INT NOT NULL,
    `order_id` BINARY(16) NULL,
    `order_number` VARCHAR(64) NULL,
    `ref` VARCHAR(64) NULL,
    `reason` VARCHAR(255) NULL,
    `available_at` DATETIME(3) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.bonus_entry.order_type` (`order_id`, `type`),
    UNIQUE KEY `uniq.bonus_entry.ref` (`customer_id`, `type`, `ref`),
    KEY `idx.bonus_entry.customer` (`customer_id`, `available_at`),
    CONSTRAINT `fk.bonus_entry.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `bonus_member` (
    `customer_id` BINARY(16) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `referral_code` VARCHAR(16) NULL,
    `source` VARCHAR(32) NULL,
    `joined_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`customer_id`),
    UNIQUE KEY `uniq.bonus_member.code` (`referral_code`),
    CONSTRAINT `fk.bonus_member.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `bonus_referral` (
    `customer_id` BINARY(16) NOT NULL,
    `referrer_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`customer_id`),
    KEY `idx.bonus_referral.referrer` (`referrer_id`),
    CONSTRAINT `fk.bonus_referral.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.bonus_referral.referrer_id` FOREIGN KEY (`referrer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
