<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Ops\Application\HeartbeatStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ops:heartbeat', description: 'Enregistre le battement de cœur (dernier succès) d\'une tâche planifiée ; appelé par ops/bin/run-job.')]
final class OpsHeartbeatCommand extends Command
{
    public function __construct(private readonly HeartbeatStore $heartbeats)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Nom de la tâche.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->heartbeats->beat((string) $input->getArgument('job'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
