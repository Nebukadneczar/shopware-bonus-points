<?php declare(strict_types=1);

namespace MaxxpetBonus\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Version 1.2.0:
 *  - Spalte `ref` im Journal (Einmaligkeit von Aktionen, z. B. „Registrierung“, Bewertung X)
 *  - Teilnehmer-Tabelle (Teilnahme, Sperre, Empfehlungscode)
 *  - Empfehlungs-Tabelle (wer wurde von wem geworben)
 */
class Migration1794355200MembersAndRefs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794355200;
    }

    public function update(Connection $connection): void
    {
        $hasRef = $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'maxxpet_bonus_entry\' AND COLUMN_NAME = \'ref\''
        );
        if ((int) $hasRef === 0) {
            $connection->executeStatement('ALTER TABLE `maxxpet_bonus_entry` ADD COLUMN `ref` VARCHAR(64) NULL AFTER `order_number`');
            $connection->executeStatement('ALTER TABLE `maxxpet_bonus_entry` ADD UNIQUE KEY `uniq.maxxpet_bonus_entry.ref` (`customer_id`, `type`, `ref`)');
        }

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `maxxpet_bonus_member` (
    `customer_id` BINARY(16) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `referral_code` VARCHAR(16) NULL,
    `source` VARCHAR(32) NULL,
    `joined_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`customer_id`),
    UNIQUE KEY `uniq.maxxpet_bonus_member.code` (`referral_code`),
    CONSTRAINT `fk.maxxpet_bonus_member.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `maxxpet_bonus_referral` (
    `customer_id` BINARY(16) NOT NULL,
    `referrer_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`customer_id`),
    KEY `idx.maxxpet_bonus_referral.referrer` (`referrer_id`),
    CONSTRAINT `fk.maxxpet_bonus_referral.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.maxxpet_bonus_referral.referrer_id` FOREIGN KEY (`referrer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
