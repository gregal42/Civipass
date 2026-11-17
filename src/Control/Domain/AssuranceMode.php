<?php

declare(strict_types=1);

namespace App\Control\Domain;

/** Mode d'assurance du contrôle hors connexion (DM-130 §5). */
enum AssuranceMode: string
{
    /** Verdict positif hors ligne : orange « valide selon la dernière synchronisation », sous bandeau permanent. */
    case Standard = 'STANDARD';
    /** Hors ligne, jamais de verdict positif : « Indéterminé : confirmation en ligne requise » ; seuls les cas négatifs connus restent affichés. */
    case Strict = 'STRICT';
}
