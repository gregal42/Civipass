<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;

/** Événement auquel la commune participe (hors invitation en attente) : sélection de la liste. */
final readonly class RequestEventOption
{
    public function __construct(
        public string $eventId,
        public string $name,
        public EventStatus $status,
        public string $timezone,
        public ParticipationStatus $participationStatus,
    ) {
    }
}
