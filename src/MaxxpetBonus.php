<?php declare(strict_types=1);

namespace MaxxpetBonus;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class MaxxpetBonus extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Punktedaten nur löschen, wenn beim Deinstallieren „Daten löschen“ gewählt wurde
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        foreach (['maxxpet_bonus_referral', 'maxxpet_bonus_member', 'maxxpet_bonus_entry'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
