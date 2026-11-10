<?php declare(strict_types=1);

namespace Bonus\Command;

use Bonus\Service\BonusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bonus:konto', description: 'Zeigt Punktestand, Level und Buchungen eines Kunden (nach E-Mail-Adresse)')]
class KontoCommand extends Command
{
    public function __construct(private readonly BonusService $bonus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des Kunden');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customers = $this->bonus->findCustomers((string) $input->getArgument('email'));
        if ($customers === []) {
            $output->writeln('<error>Kein Kunde mit Kundenkonto zu dieser E-Mail-Adresse gefunden.</error>');

            return Command::FAILURE;
        }
        foreach ($customers as $c) {
            $a = $this->bonus->account($c['id']);
            $output->writeln(sprintf('<info>%s (%s)</info>', $c['name'], $c['email']));
            $output->writeln(sprintf('  Verfügbar: %d | Ausstehend (Sperrfrist): %d | Statuspunkte: %d | Level: %s (×%s)', $a['available'], $a['pending'], $a['status'], $a['level']['name'], $a['level']['mult']));
            foreach ($this->bonus->entries($c['id'], 30) as $e) {
                $output->writeln(sprintf('  %s  %+6d  %-34s %s%s', substr($e['createdAt'], 0, 10), $e['points'], $e['label'], (string) $e['reason'], $e['pending'] ? '  [ausstehend bis ' . substr($e['availableAt'], 0, 10) . ']' : ''));
            }
        }

        return Command::SUCCESS;
    }
}
