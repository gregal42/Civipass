<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;

/**
 * Ligne d'une liste d'événements pour une commune donnée. `myParticipationStatus` est le statut de LA commune qui
 * consulte (null pour l'organisatrice, qui n'est pas « invitée »).
 */
final readonly class EventListItem
{
    public function __construct(
        public string $eventId,
        public string $publicReference,
        public string $name,
        public EventStatus $status,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public string $typeCode,
        public string $typeLabel,
        public string $organizerMunicipalityId,
        public string $organizerMunicipalityName,
        public ?ParticipationStatus $myParticipationStatus,
    ) {
    }
}
