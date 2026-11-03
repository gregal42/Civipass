<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\ParticipationStatus;

/**
 * Projection de GESTION réservée à la commune organisatrice (visibilité complète sur les participations de son
 * événement). Reste une projection de lecture : aucune entité Doctrine, aucun agent (pas d'auteur d'invitation).
 */
final readonly class EventParticipationDetails
{
    public function __construct(
        public string $participationId,
        public string $municipalityId,
        public string $municipalityName,
        public ParticipationStatus $status,
        public bool $organizer,
        public ?string $invitationMessage,
        public ?\DateTimeImmutable $invitedAt,
        public ?\DateTimeImmutable $lastInvitationSentAt,
        public ?\DateTimeImmutable $activatedAt,
        public ?\DateTimeImmutable $suspendedAt,
        public ?\DateTimeImmutable $closedAt,
        public ?string $suspensionReason,
    ) {
    }
}
