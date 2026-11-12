<?php

declare(strict_types=1);

namespace App\Event\UI\Console;

use App\Event\Application\Dormancy\EventDormancyDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:event:dormancy-report', description: 'Liste (lecture seule, sans donnée personnelle) les événements sans fin planifiée et inactifs.')]
final class EventDormancyReportCommand extends Command
{
    public function __construct(private readonly EventDormancyDetector $detector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $events = $this->detector->scan();
        foreach ($events as $e) {
            $output->writeln(sprintf('%s %s commune=%s inactif=%dj derniere_activite=%s etat=%s%s', $e->eventId, $e->reference, $e->organizerId, $e->inactiveDays, $e->lastActivity->format('Y-m-d'), $e->state, null === $e->snoozedUntil ? '' : ' report_jusqu_au='.$e->snoozedUntil->format('Y-m-d')));
        }
        $output->writeln(sprintf('%d événement(s) dormant(s). Aucune clôture, aucun changement de droit.', \count($events)));

        return Command::SUCCESS;
    }
}
