<?php

declare(strict_types=1);

namespace App\Municipality\UI\Console;

use App\Municipality\Application\NationalCommuneSource;
use App\Municipality\Application\NationalCommuneSourceUnavailable;
use App\Municipality\Application\NationalCommuneSynchronizer;
use App\Municipality\Infrastructure\JsonCommuneDecoder;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe et synchronise le référentiel national des communes depuis la source officielle (API Géo), ou depuis un fichier JSON
 * local de même format (--file). N'écrit que dans le référentiel : aucune commune inscrite, aucun droit n'est modifié.
 * À planifier (cron) environ une fois par mois : le COG change peu (fusions de communes au 1er janvier).
 */
#[AsCommand(name: 'app:communes:sync', description: 'Importe et synchronise le référentiel national des communes (API Géo / COG INSEE).')]
final class SynchronizeNationalCommunesCommand extends Command
{
    public function __construct(
        private readonly NationalCommuneSource $source,
        private readonly NationalCommuneSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule et affiche le bilan sans rien écrire.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Fichier JSON local (format API Géo) au lieu de l\'API.')
            ->addOption('allow-partial', null, InputOption::VALUE_NONE, 'Import partiel (fichier réduit) : écrit ce qu\'il reçoit, ne désactive aucune commune.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getOption('file');
        try {
            if (\is_string($file)) {
                $json = @file_get_contents($file);
                if (false === $json) {
                    $io->error('Fichier illisible.');

                    return Command::FAILURE;
                }
                $records = JsonCommuneDecoder::decode($json);
                $sourceName = 'fichier:'.basename($file);
            } else {
                $records = $this->source->fetchAll();
                $sourceName = $this->source->name();
            }
            $report = $this->synchronizer->synchronize($records, $sourceName, (bool) $input->getOption('dry-run'), (bool) $input->getOption('allow-partial'));
        } catch (NationalCommuneSourceUnavailable|DomainRuleViolation $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Source' => $sourceName],
            ['Mode' => $report->dryRun ? 'essai à blanc (rien n\'est écrit)' : ($report->partial ? 'import partiel' : 'synchronisation complète')],
            ['Reçues' => (string) $report->received],
            ['Créées' => (string) $report->created],
            ['Mises à jour' => (string) $report->updated],
            ['Inchangées' => (string) $report->unchanged],
            ['Désactivées' => (string) $report->deactivated],
            ['Ignorées (invalides)' => (string) $report->ignored],
        );
        $io->success($report->dryRun ? 'Essai à blanc terminé.' : 'Référentiel synchronisé.');

        return Command::SUCCESS;
    }
}
