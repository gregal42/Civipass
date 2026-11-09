<?php

declare(strict_types=1);

namespace App\Permit\UI\Console;

use App\Permit\Application\PermitBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rattrapage des laissez-passer des approbations existantes (LOT 05.1, DM-112). RÉSERVÉ AU DÉVELOPPEMENT : refusé dans tout autre
 * environnement. Jamais exécuté automatiquement. `--dry-run` : compte sans rien écrire. Idempotent.
 */
#[AsCommand(name: 'app:permit:backfill', description: 'Crée les laissez-passer et autorisations manquants pour les approbations existantes (développement seulement)')]
final class BackfillPermitsCommand extends Command
{
    public function __construct(
        private readonly PermitBackfill $backfill,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter ce qui serait créé, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('dev' !== $this->environment) {
            $io->error('Cette commande est réservée à l\'environnement de développement.');

            return Command::FAILURE;
        }
        $report = $this->backfill->run((bool) $input->getOption('dry-run'));
        $io->definitionList(
            ['Mode' => $report->dryRun ? 'simulation (rien n\'est écrit)' : 'exécution'],
            ['Approbations sans autorisation' => (string) $report->approvalsMissing],
            ['Laissez-passer à créer' => (string) $report->permitsToCreate],
            ['Laissez-passer créés' => (string) $report->permitsCreated],
            ['Autorisations ajoutées' => (string) $report->authorizationsAdded],
        );
        foreach ($report->errors as $error) {
            $io->error($error);
        }

        return [] === $report->errors ? Command::SUCCESS : Command::FAILURE;
    }
}
