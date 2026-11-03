<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\ParticipantListVisibility;

/**
 * Résultat d'une consultation des participations d'un événement, déjà filtré selon la visibilité de la commune.
 * Un seul des trois contenus est renseigné, selon `visibility` : jamais de champ interne « à masquer plus tard ».
 */
final readonly class ParticipantList
{
    /**
     * @param list<EventParticipationDetails> $details   visibilité complète (commune organisatrice)
     * @param list<EventParticipationSummary> $summaries coordination (communes participantes)
     */
    public function __construct(
        public ParticipantListVisibility $visibility,
        public array $details = [],
        public array $summaries = [],
        public ?InvitationView $invitation = null,
    ) {
    }
}
