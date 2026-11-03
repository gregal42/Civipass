<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\AccessDenialReason;

/**
 * Disponibilité d'une action pour l'utilisateur, décidée par la politique d'accès : `reason` n'est renseigné que si
 * l'action est refusée. L'interface traduit le motif en phrase sûre (jamais d'information interne).
 */
final readonly class ActionAvailability
{
    public function __construct(public EventAction $action, public bool $allowed, public ?AccessDenialReason $reason = null)
    {
    }
}
