<?php

declare(strict_types=1);

namespace App\Notification\UI\Console;

use App\Notification\Application\SecretPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Purge des secrets chiffrés (LOT 05.3.2, D13) : définitifs ou âgés de plus de 48 h. Affiche des compteurs seulement. Idempotente. */
#[AsCommand(name: 'app:notifications:purge-secrets', description: 'Supprime les secrets chiffrés traités ou échus et révoque les liens automatiques non transmis')]
final class PurgeSecretsCommand extends Command
{
    public function __construct(private readonly SecretPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = (new SymfonyStyle($input, $output));
        $result = $this->purger->purge();
        $report->definitionList(
            ['Secrets supprimés' => (string) $result['purged']],
            ['Liens automatiques révoqués (jamais transmis)' => (string) $result['revoked']],
            ['Messages passés en échec (secret échu)' => (string) $result['expired']],
        );

        return Command::SUCCESS;
    }
}
