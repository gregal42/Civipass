<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/**
 * Historique intercommunal après clôture (U6) : uniquement l'identifiant, le nom et l'indicateur d'organisatrice.
 * Ni statut (toutes les participations sont clôturées), ni date, ni motif, ni invitation jamais acceptée.
 */
final readonly class EventParticipantHistoryEntry
{
    public function __construct(
        public string $municipalityId,
        public string $municipalityName,
        public bool $organizer,
    ) {
    }
}
