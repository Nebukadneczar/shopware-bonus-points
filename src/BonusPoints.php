<?php declare(strict_types=1);

namespace BonusPoints;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class BonusPoints extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Punktedaten nur löschen, wenn beim Deinstallieren „Daten löschen“ gewählt wurde
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        foreach (['bonus_points_referral', 'bonus_points_member', 'bonus_points_entry'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
