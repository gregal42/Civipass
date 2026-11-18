<?php

declare(strict_types=1);

namespace App\Request\UI\Console;

use App\Request\Application\AbandonedDraftPurger;
use App\Request\UI\Http\PublicRequestDraft;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les brouillons du formulaire public restés inactifs plus de 30 minutes dans les sessions anonymes, avec leurs justificatifs
 * temporaires (LOT 10, O-2). À lancer périodiquement par le planificateur du système, sous le compte qui possède les sessions (celui du
 * pool PHP-FPM). Ne touche jamais une session authentifiée. Aucune donnée personnelle n'est affichée : seulement des compteurs.
 */
#[AsCommand(
    name: 'app:request-drafts:purge',
    description: 'Supprime les brouillons publics abandonnés (> 30 min) des sessions anonymes, avec leurs pièces temporaires.',
)]
final class PurgeAbandonedDraftsCommand extends Command
{
    public function __construct(private readonly AbandonedDraftPurger $purger, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Dossier des sessions PHP (défaut : celui de la configuration PHP)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compter sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');
        $path = \is_string($path) && '' !== $path ? $path : ('' !== session_save_path() ? session_save_path() : sys_get_temp_dir());
        // « N;/chemin » ou « N;MODE;/chemin » (profondeur, mode) : le dossier est le dernier segment.
        $path = (string) preg_replace('#^\d+;(?:[0-7]+;)?#', '', $path);
        if (!is_dir($path) || !is_readable($path)) {
            $io->error('Dossier des sessions introuvable ou illisible.');

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        $r = $this->purger->purge($path, PublicRequestDraft::TTL_SECONDS, $this->clock->now()->getTimestamp(), $dry);
        $io->success(sprintf('%sbrouillons=%d pièces_temporaires=%d sessions_authentifiées_ignorées=%d sessions_lues=%d', $dry ? 'SIMULATION : ' : '', $r['drafts'], $r['files'], $r['skipped_authenticated'], $r['scanned']));

        return Command::SUCCESS;
    }
}
