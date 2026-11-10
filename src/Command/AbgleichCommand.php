<?php declare(strict_types=1);

namespace BonusPoints\Command;

use BonusPoints\Service\AdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bonus-points:abgleich', description: 'Schreibt Punkte für bezahlte Bestellungen nach, die noch keine Buchung haben (Standard: nur Vorschau)')]
class AbgleichCommand extends Command
{
    public function __construct(private readonly AdminService $admin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('seit', null, InputOption::VALUE_REQUIRED, 'Nur Bestellungen ab diesem Datum (JJJJ-MM-TT), Pflichtangabe')
            ->addOption('anwenden', null, InputOption::VALUE_NONE, 'Wirklich buchen (ohne diese Option nur Vorschau)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seit = (string) $input->getOption('seit');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $seit)) {
            $output->writeln('<error>Bitte --seit=JJJJ-MM-TT angeben, z. B. --seit=2026-11-10 (nur Bestellungen ab diesem Tag).</error>');

            return Command::FAILURE;
        }
        $apply = (bool) $input->getOption('anwenden');
        $res = $this->admin->importOrders($seit, $apply);
        $output->writeln(sprintf('%d bezahlte Bestellung(en) ohne Punktebuchung seit %s.%s', $res['count'], $seit, $apply ? '' : ' (Vorschau, nichts gebucht)'));
        foreach ($res['lines'] as $line) {
            $output->writeln('  ' . $line);
        }
        if ($apply) {
            $output->writeln(sprintf('<info>%d Bestellung(en) gutgeschrieben.</info>', $res['booked']));
        }

        return Command::SUCCESS;
    }
}
