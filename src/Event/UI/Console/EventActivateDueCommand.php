<?php

declare(strict_types=1);

namespace App\Event\UI\Console;

use App\Event\Application\Service\ScheduledEventActivation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:event:activate-due', description: 'Active les événements en brouillon dont l\'heure de début est atteinte et dont une zone est prête (`--dry-run` pour simuler).')]
final class EventActivateDueCommand extends Command
{
    public function __construct(private readonly ScheduledEventActivation $activation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : compte les événements activables sans rien écrire.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'événements par passage.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = !(bool) $input->getOption('dry-run');
        $r = $this->activation->run($execute, max(1, (int) $input->getOption('limit')));
        $output->writeln($execute ? 'MODE EXÉCUTION' : 'SIMULATION (aucune écriture)');
        $output->writeln(sprintf('échus=%d %s=%d non_prêts=%d ignorés=%d', $r['due'], $execute ? 'activés' : 'activables', $r['activated'], $r['notReady'], $r['skipped']));

        return Command::SUCCESS;
    }
}
