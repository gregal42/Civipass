<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;

/**
 * Part demandée à une commune : sa participation à l'événement et les zones choisies dans cette participation.
 */
final readonly class RequestedTerritory
{
    /**
     * @param list<EventZone> $zones
     */
    public function __construct(
        public EventParticipation $participation,
        public array $zones,
    ) {
    }
}
