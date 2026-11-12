<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Retention\Application\PurgeDisabled;
use App\Retention\Application\RetentionPurgeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Purge de conservation (LOT 05.6.4) : SIMULATION par défaut ; `--execute` refusé tant que RETENTION_PURGE_ENABLED=0. */
#[AsCommand(name: 'app:retention:purge', description: 'Purge les dossiers échus (simulation par défaut ; --execute exige RETENTION_PURGE_ENABLED=1).')]
final class RetentionPurgeCommand extends Command
{
    public function __construct(private readonly RetentionPurgeService $purge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Exécute réellement la purge.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de dossiers par passage.', '100')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'Limite à un événement.')
            ->addOption('municipality', null, InputOption::VALUE_REQUIRED, 'Limite à une commune.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        try {
            $report = $this->purge->run($execute, max(1, (int) $input->getOption('limit')), $input->getOption('event') ?: null, $input->getOption('municipality') ?: null);
        } catch (PurgeDisabled $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        RetentionRunPrinter::print($output, $report, $execute);

        return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
