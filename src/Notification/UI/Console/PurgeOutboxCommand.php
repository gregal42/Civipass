<?php

declare(strict_types=1);

namespace App\Notification\UI\Console;

use App\Notification\Application\OutboxPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge des métadonnées de l'outbox (LOT 05.3.5, D13) : messages DÉFINITIFS de plus de N jours (90 par défaut, durée provisoire soumise à la
 * validation RGPD). Prévue une fois par jour (cron de l'hôte, NON configuré). Idempotente, sûre en présence du distributeur. Compteurs seulement.
 */
#[AsCommand(name: 'app:notifications:purge-outbox', description: 'Supprime les messages d\'outbox définitifs de plus de 90 jours (compteurs seulement)')]
final class PurgeOutboxCommand extends Command
{
    public function __construct(private readonly OutboxPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Durée de conservation des messages définitifs, en jours', (string) OutboxPurger::DEFAULT_RETENTION_DAYS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de messages examinés par catégorie', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->purger->purge(max(1, (int) $input->getOption('retention-days')), max(1, (int) $input->getOption('limit')));
        $io->definitionList(
            ['Messages supprimés' => (string) $report['purged']],
            ['Messages de mise à disposition conservés (laissez-passer encore utilisable)' => (string) $report['retained']],
            ['Lignes écartées à la relecture' => (string) $report['skipped']],
        );

        return Command::SUCCESS;
    }
}
