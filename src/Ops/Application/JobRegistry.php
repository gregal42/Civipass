<?php

declare(strict_types=1);

namespace App\Ops\Application;

/**
 * Tâches d'exploitation et ancienneté maximale tolérée de leur dernier succès (LOT 05.6.7). Seules les tâches NON destructives activées à la mise en service y figurent, à l'exception des purges TECHNIQUES de données temporaires jamais rattachées à un dossier (justificatifs et brouillons abandonnés du dépôt public) ; la purge,
 * l'exécution d'un effacement et le rejeu ne sont jamais planifiés. L'alerte de dormance et l'avis de conservation restent désactivés jusqu'aux validations nécessaires.
 */
final class JobRegistry
{
    /** @var array<string, int> secondes */
    public const GO_LIVE = [
        'backup' => 129600,            // quotidienne : 36 h
        'backup-verify' => 691200,     // hebdomadaire : 8 j
        'anchor' => 7200,              // horaire : 2 h
        'register-check' => 129600,
        'integrity-check' => 129600,
        'rights-overdue' => 129600,
        'purge-simulation' => 691200,
        'notifications-dispatch' => 600, // chaque minute : 10 min
        'event-activation' => 600,       // chaque minute : 10 min (DM-123)
        'request-drafts-purge' => 3600,  // toutes les 10 min : 1 h (purge technique des brouillons publics abandonnés, sessions anonymes seulement)
        'request-uploads-purge' => 7200, // horaire : 2 h (purge technique des justificatifs temporaires abandonnés, jamais les pièces des dossiers)
    ];
}
