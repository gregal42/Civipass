<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Accès opérationnel demandé sur un événement CLOS (LOT 05.6.2, DM-120). Levée seulement APRÈS le contrôle des habilitations : elle ne doit
 * jamais révéler l'existence d'une ressource à un utilisateur non habilité. L'espace municipal la traduit en 410 neutre, sans aucune donnée.
 */
final class ClosedEventAccess extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Événement clos : accès opérationnel fermé.');
    }
}
