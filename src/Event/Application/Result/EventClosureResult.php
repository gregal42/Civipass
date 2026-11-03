<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\EventStatus;

/**
 * Résultat de la clôture globale (DM-092) : l'événement et chaque participation ouverte clôturée avec lui ; les
 * participations déjà clôturées ne figurent pas (aucune mutation, aucun audit). `batchId` regroupe toutes les traces.
 */
final readonly class EventClosureResult
{
    /**
     * @param list<ClosedParticipation> $closedParticipations
     */
    public function __construct(
        public string $eventId,
        public EventStatus $previousStatus,
        public array $closedParticipations,
        public string $batchId,
    ) {
    }
}
