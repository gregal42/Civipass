<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Entrée de EventParticipationService::invite. La commune invitée est désignée par son identifiant stable (D4) ; la
 * recherche par nom ou code INSEE relève de la future interface, jamais du service.
 */
final readonly class InviteMunicipalityCommand
{
    public function __construct(
        public string $eventId,
        public string $municipalityId,
        public ?string $message = null,
    ) {
    }
}
