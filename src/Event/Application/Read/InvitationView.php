<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;

/**
 * Ce qu'une commune simplement invitée voit : sa propre invitation et les informations minimales nécessaires à sa
 * décision. Jamais la liste des autres communes.
 */
final readonly class InvitationView
{
    public function __construct(
        public string $publicReference,
        public string $eventName,
        public string $typeLabel,
        public EventStatus $eventStatus,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public string $organizerMunicipalityName,
        public ParticipationStatus $status,
        public ?string $invitationMessage,
        public ?\DateTimeImmutable $invitedAt,
    ) {
    }
}
