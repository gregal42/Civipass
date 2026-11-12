<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Retention\Application\RestoreChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Contrôle d'intégrité de routine (lecture seule, sans donnée personnelle) : registre chaîné, ancrage, enregistrements, fichiers, compteurs de clôture. */
#[AsCommand(name: 'app:ops:integrity-check', description: 'Contrôle d\'intégrité de routine (lecture seule) ; code de sortie non nul s\'il reste une anomalie bloquante.')]
final class OpsIntegrityCheckCommand extends Command
{
    public function __construct(private readonly RestoreChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->checker->check();
        $counts = [];
        foreach ($report->items as $item) {
            $counts[$item->code.' ('.$item->severity.')'] = ($counts[$item->code.' ('.$item->severity.')'] ?? 0) + 1;
        }
        foreach ($counts as $label => $n) {
            $output->writeln(sprintf('%s : %d', $label, $n));
        }
        $blocking = \count($report->blocking());
        $output->writeln(sprintf('registre=%d lignes, ancrage=%d points, preuve=%s, anomalies_bloquantes=%d', $report->chainLines, $report->anchors, $report->proofSound() ? 'saine' : 'NON SAINE', $blocking));

        return 0 === $blocking && $report->proofSound() ? Command::SUCCESS : Command::FAILURE;
    }
}
