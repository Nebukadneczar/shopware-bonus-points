<?php declare(strict_types=1);

namespace BonusPoints\Command;

use BonusPoints\Service\BonusService;
use BonusPoints\Service\MemberService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bonus-points:sperre', description: 'Sperrt einen Kunden für das Bonusprogramm (oder hebt die Sperre auf)')]
class SperreCommand extends Command
{
    public function __construct(
        private readonly BonusService $bonus,
        private readonly MemberService $members,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des Kunden')
            ->addOption('aufheben', null, InputOption::VALUE_NONE, 'Sperre aufheben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customers = $this->bonus->findCustomers((string) $input->getArgument('email'));
        if (\count($customers) !== 1) {
            $output->writeln('<error>' . (\count($customers) === 0 ? 'Kein Kunde mit Kundenkonto gefunden.' : 'Die E-Mail-Adresse ist mehrfach vorhanden.') . '</error>');

            return Command::FAILURE;
        }
        if ($input->getOption('aufheben')) {
            $this->members->unblock($customers[0]['id']);
            $output->writeln('<info>Sperre aufgehoben für ' . $customers[0]['email'] . '.</info>');
        } else {
            $this->members->block($customers[0]['id']);
            $output->writeln('<info>Gesperrt: ' . $customers[0]['email'] . '. Vorhandene Punkte bleiben, es gibt aber keine neuen und kein Einlösen.</info>');
        }

        return Command::SUCCESS;
    }
}
