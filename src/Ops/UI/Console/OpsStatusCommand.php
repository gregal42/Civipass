<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Ops\Application\OpsStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ops:status', description: 'État des tâches d\'exploitation (battements de cœur) ; code de sortie non nul si une tâche est en retard ou jamais exécutée, ou si la maintenance est oubliée (plus de 24 h).')]
final class OpsStatusCommand extends Command
{
    public function __construct(private readonly OpsStatus $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'Sous-ensemble de tâches, séparées par des virgules.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = \is_string($input->getOption('jobs')) && '' !== $input->getOption('jobs') ? explode(',', $input->getOption('jobs')) : null;
        $report = $this->status->report($jobs);
        if ($report['maintenance']) {
            $output->writeln($report['maintenanceForgotten'] ? 'MAINTENANCE active depuis plus de 24 h (ou date illisible) : à lever ou à justifier.' : 'MAINTENANCE active : tâches suspendues (attendu).');

            return $report['maintenanceForgotten'] ? Command::FAILURE : Command::SUCCESS;
        }
        $bad = 0;
        foreach ($report['jobs'] as $job => $line) {
            $output->writeln(sprintf('%s %s %s', $job, $line['state'], null === $line['ageSeconds'] ? '-' : $line['ageSeconds'].'s'));
            $bad += OpsStatus::OK === $line['state'] ? 0 : 1;
        }

        return 0 === $bad ? Command::SUCCESS : Command::FAILURE;
    }
}
