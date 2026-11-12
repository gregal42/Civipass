<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

use App\Identity\Domain\User;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Bandeau de dormance de la fiche d'un événement (LOT 05.6.5) : visible UNIQUEMENT par l'administrateur et l'organisateur de la commune organisatrice (même règle que le report et
 * les courriels, relue côté serveur). Information seulement : aucune action de clôture, aucun changement de droit.
 */
final class EventDormancyBanner
{
    public function __construct(private readonly EventDormancySnoozeService $access, private readonly EventDormancyDetector $detector)
    {
    }

    public function forViewer(User $user, string $municipalityId, string $eventId): ?DormantEvent
    {
        try {
            $id = $this->access->authorized($user, $municipalityId, $eventId);
        } catch (OperationNotAllowed) {
            return null;
        }

        return $this->detector->forEvent($id);
    }
}
