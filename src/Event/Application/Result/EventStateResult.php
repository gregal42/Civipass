<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\EventStatus;

/**
 * Résultat d'une modification ou d'une transition d'événement. `changedFields` liste les champs réellement modifiés
 * (vide : aucune écriture ni audit).
 */
final readonly class EventStateResult
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $eventId,
        public string $publicReference,
        public EventStatus $status,
        public array $changedFields = [],
    ) {
    }
}
