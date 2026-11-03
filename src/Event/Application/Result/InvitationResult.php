<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\ParticipationStatus;

/**
 * Résultat d'une invitation ou d'une réexpédition. `lastInvitationSentAt` enregistre la DEMANDE d'envoi : aucun
 * mécanisme de notification (e-mail, SMS) n'existe dans le périmètre technique validé, donc aucun envoi n'est effectué ni
 * confirmé ici. `proposedZoneId` n'est renseigné qu'à la création de la participation.
 */
final readonly class InvitationResult
{
    public function __construct(
        public string $participationId,
        public string $municipalityId,
        public ParticipationStatus $status,
        public \DateTimeImmutable $lastInvitationSentAt,
        public ?string $proposedZoneId = null,
    ) {
    }
}
