<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Ops\Application\LossFindings;
use App\Ops\Application\MaintenanceMode;
use App\Retention\Application\RestoreChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:retention:restore-check', description: 'Contrôle de restauration (lecture seule) : dossiers et événements réintroduits, preuve externe, pertes possibles ; code de sortie non nul s\'il reste une anomalie bloquante.')]
final class RestoreCheckCommand extends Command
{
    public function __construct(private readonly RestoreChecker $checker, private readonly MaintenanceMode $maintenance, private readonly LossFindings $findings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table ou json.', 'table')
            ->addOption('external-anchors', null, InputOption::VALUE_REQUIRED, 'Copie EXTERNE des points d\'ancrage (courriel, journal distant, autre serveur) à confronter à la chaîne.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $external = $input->getOption('external-anchors');
        $report = $this->checker->check(\is_string($external) && '' !== $external ? $external : null);
        if ('json' === $input->getOption('format')) {
            $output->writeln(json_encode(['hash' => $report->hash, 'proofSound' => $report->proofSound(), 'items' => array_map(static fn ($i): array => $i->toArray(), $report->items)], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        } else {
            foreach ($report->items as $i) {
                $output->writeln(sprintf('%s %s %s record=%s dossier=%s demande=%s %s', $i->severity, $i->code, $i->qualification, $i->recordId, $i->dossierId, $i->requestId, $i->detail));
            }
        }
        if ($this->maintenance->active()) {
            $this->findings->record($report->items); // en maintenance, les constats de perte sont consignés (voir app:ops:release)
        }
        $blocking = \count($report->blocking());
        $output->writeln(sprintf('rapport=%s lignes_registre=%d points_ancrage=%d preuve=%s anomalies_bloquantes=%d rejouables=%d', $report->hash, $report->chainLines, $report->anchors, $report->proofSound() ? 'saine' : 'NON SAINE', $blocking, \count($report->replayable('REINTRODUCED_DOSSIER')) + \count($report->replayable('REINTRODUCED_SHARED'))));
        $output->writeln('Lecture seule : rien n\'a été modifié. Le rejeu exige app:retention:replay avec ce rapport et une référence d\'approbation.');

        return 0 === $blocking && $report->proofSound() ? Command::SUCCESS : Command::FAILURE;
    }
}
