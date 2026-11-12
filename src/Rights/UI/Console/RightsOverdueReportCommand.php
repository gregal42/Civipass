<?php

declare(strict_types=1);

namespace App\Rights\UI\Console;

use App\Rights\Application\RightsRequestReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:rights:overdue-report', description: 'Liste (lecture seule) les demandes de droits dont l\'échéance est dépassée : références, communes et dates seulement.')]
final class RightsOverdueReportCommand extends Command
{
    public function __construct(private readonly RightsRequestReader $reader)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->reader->overdue();
        foreach ($rows as $r) {
            $output->writeln(sprintf('%s commune=%s %s %s echeance=%s', $r['reference'], $r['municipality'], $r['kind']->value, $r['status']->value, $r['dueAt']->format('Y-m-d')));
        }
        $output->writeln(sprintf('%d demande(s) en retard. Aucune donnée de personne, aucune modification.', \count($rows)));

        return Command::SUCCESS;
    }
}
