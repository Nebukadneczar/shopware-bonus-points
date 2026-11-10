<?php declare(strict_types=1);

namespace BonusPoints\Command;

use BonusPoints\Service\BonusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bonus-points:buchen', description: 'Bucht Punkte manuell auf ein Kundenkonto (positiv oder negativ, sofort wirksam)')]
class BuchenCommand extends Command
{
    public function __construct(private readonly BonusService $bonus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des Kunden')
            ->addArgument('punkte', InputArgument::REQUIRED, 'Punkte, z. B. 100 oder -50')
            ->addArgument('grund', InputArgument::REQUIRED, 'Begründung (wird dem Kunden angezeigt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $points = (string) $input->getArgument('punkte');
        $reason = trim((string) $input->getArgument('grund'));
        if (!preg_match('/^-?\d+$/', $points) || (int) $points === 0) {
            $output->writeln('<error>Punkte müssen eine ganze Zahl ungleich 0 sein.</error>');

            return Command::FAILURE;
        }
        if ($reason === '') {
            $output->writeln('<error>Eine Begründung ist Pflicht.</error>');

            return Command::FAILURE;
        }
        $customers = $this->bonus->findCustomers((string) $input->getArgument('email'));
        if (\count($customers) !== 1) {
            $output->writeln('<error>' . (\count($customers) === 0 ? 'Kein Kunde mit Kundenkonto gefunden.' : 'Die E-Mail-Adresse ist mehrfach vorhanden (mehrere Verkaufskanäle). Bitte Support fragen.') . '</error>');

            return Command::FAILURE;
        }
        $this->bonus->addManual($customers[0]['id'], (int) $points, $reason);
        $a = $this->bonus->account($customers[0]['id']);
        $output->writeln(sprintf('<info>Gebucht: %+d Punkte für %s. Verfügbar jetzt: %d.</info>', (int) $points, $customers[0]['email'], $a['available']));

        return Command::SUCCESS;
    }
}
