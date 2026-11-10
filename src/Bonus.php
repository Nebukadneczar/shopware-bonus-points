<?php declare(strict_types=1);

namespace Bonus;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Bonus extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Punktedaten nur löschen, wenn beim Deinstallieren „Daten löschen“ gewählt wurde
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        foreach (['bonus_referral', 'bonus_member', 'bonus_entry'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
