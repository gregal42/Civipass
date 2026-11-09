<?php

declare(strict_types=1);

namespace App\Notification\UI\Console;

use App\Notification\Application\NotificationDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Distributeur des notifications du bénéficiaire (LOT 05.3.3, DT-049) : un passage, prévu pour s'exécuter toutes les minutes (cron de l'hôte,
 * NON configuré par ce lot). Sûr en présence de plusieurs processus simultanés (réservation sous verrou de ligne, bail de 10 minutes). Durée
 * bornée (`--max-seconds`) pour ne pas chevaucher le passage suivant. Affiche des compteurs seulement : jamais d'adresse, de secret ni d'identifiant.
 */
#[AsCommand(name: 'app:notifications:dispatch', description: 'Envoie les notifications échues du bénéficiaire (un passage ; sûr en concurrence)')]
final class DispatchCommand extends Command
{
    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de messages traités par passage', '100')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Durée maximale d\'un passage, en secondes', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->dispatcher->run(max(1, (int) $input->getOption('limit')), max(1, (int) $input->getOption('max-seconds')));
        $rows = [];
        foreach ($report->outcomes as $outcome => $count) {
            $rows[] = [$outcome => (string) $count];
        }
        $io->definitionList(...($rows ?: [['Messages traités' => '0']]));
        $io->definitionList(
            ['Secrets supprimés' => (string) $report->purge['purged']],
            ['Liens automatiques révoqués (jamais transmis)' => (string) $report->purge['revoked']],
        );

        return isset($report->outcomes['error']) ? Command::FAILURE : Command::SUCCESS;
    }
}
