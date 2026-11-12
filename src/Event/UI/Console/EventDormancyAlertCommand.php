<?php

declare(strict_types=1);

namespace App\Event\UI\Console;

use App\Event\Application\Dormancy\EventDormancyAlertService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:event:dormancy-alert', description: 'Alerte les responsables des événements sans fin planifiée et inactifs (simulation par défaut ; ne clôture jamais).')]
final class EventDormancyAlertCommand extends Command
{
    public function __construct(private readonly EventDormancyAlertService $alerts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Envoie réellement les alertes (sinon simulation).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'alertes par passage.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        $r = $this->alerts->run($execute, max(1, (int) $input->getOption('limit')));
        $output->writeln($execute ? 'MODE EXÉCUTION' : 'SIMULATION (aucun envoi, aucune écriture)');
        $output->writeln(sprintf('détectés=%d alertés=%d échecs=%d ignorés=%d', $r->detected, $r->alerted, $r->failed, $r->skipped));
        foreach ($r->reasons as $reason => $n) {
            $output->writeln(sprintf('  %s : %d', $reason, $n));
        }

        return $r->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
