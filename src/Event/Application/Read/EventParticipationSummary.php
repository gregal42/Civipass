<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\ParticipationStatus;

/**
 * Projection de COORDINATION (DM-084) : le strict minimum que les communes participantes se partagent pour savoir
 * qui est impliqué. Volontairement sans motif de suspension, date, audit, message d'invitation, agent ni donnée
 * commerciale : ces champs n'existent pas dans la classe, donc ne peuvent pas être exposés par erreur.
 */
final readonly class EventParticipationSummary
{
    public function __construct(
        public string $municipalityId,
        public string $municipalityName,
        public ParticipationStatus $status,
        public bool $organizer,
    ) {
    }
}
