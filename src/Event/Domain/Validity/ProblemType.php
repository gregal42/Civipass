<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

enum ProblemType: string
{
    /** Heure locale inexistante (saut de l'heure d'été) : saisie refusée. */
    case NonexistentTime = 'NONEXISTENT_TIME';
    /** Heure locale répétée sans choix d'occurrence. */
    case MissingChoice = 'MISSING_CHOICE';
    /** Choix enregistré pour un horaire qui n'est plus ambigu (ou jamais). */
    case ObsoleteChoice = 'OBSOLETE_CHOICE';
    /** Fin résolue non postérieure au début résolu. */
    case NonPositiveDuration = 'NON_POSITIVE_DURATION';
}
