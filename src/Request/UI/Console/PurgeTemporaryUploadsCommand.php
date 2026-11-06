<?php

declare(strict_types=1);

namespace App\Request\UI\Console;

use App\Shared\Application\FileStorageInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge technique des justificatifs téléversés mais jamais rattachés à un dépôt, au-delà de 24 heures (S7). À lancer
 * périodiquement par le planificateur du système (cron). Ne touche JAMAIS la zone définitive : une pièce rattachée à un
 * dossier ne peut pas être supprimée par cette commande.
 */
#[AsCommand(
    name: 'app:request-uploads:purge',
    description: 'Supprime les justificatifs temporaires non rattachés de plus de 24 heures (jamais les pièces des dossiers).',
)]
final class PurgeTemporaryUploadsCommand extends Command
{
    public const MAX_AGE = 'PT24H';

    public function __construct(private readonly FileStorageInterface $files, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->files->deleteTemporaryOlderThan($this->clock->now()->sub(new \DateInterval(self::MAX_AGE)));
        $io->success(sprintf('%d fichier(s) temporaire(s) expiré(s) supprimé(s).', $deleted));

        return Command::SUCCESS;
    }
}
