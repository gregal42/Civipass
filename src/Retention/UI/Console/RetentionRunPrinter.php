<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Retention\Application\RetentionRunReport;
use Symfony\Component\Console\Output\OutputInterface;

/** Compte rendu de console : effectifs et motifs seulement, jamais de donnée personnelle. */
final class RetentionRunPrinter
{
    public static function print(OutputInterface $output, RetentionRunReport $r, bool $execute): void
    {
        $output->writeln($execute ? 'MODE EXÉCUTION' : 'SIMULATION (aucune écriture)');
        $output->writeln(sprintf('candidats=%d traités=%d déjà_faits=%d non_éligibles=%d partagées=%d échecs=%d fichiers=%d registre_fichier_en_échec=%d%s', $r->candidates, $r->done, $r->alreadyDone, $r->notEligible, $r->sharedPurged, $r->failed, $r->files, $r->registerFileFailures, $r->interrupted ? ' INTERROMPU' : ''));
        foreach ($r->reasons as $reason => $n) {
            $output->writeln(sprintf('  non éligible (%s) : %d', $reason, $n));
        }
        foreach ($r->counts as $table => $n) {
            $output->writeln(sprintf('  %s : %d', $table, $n));
        }
        foreach ($r->failures as $failure) {
            $output->writeln('  échec : '.$failure);
        }
    }
}
