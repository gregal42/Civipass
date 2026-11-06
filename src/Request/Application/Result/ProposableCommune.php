<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;

/** Commune ayant au moins une zone proposable au dépôt, avec ces zones (DM-104). */
final readonly class ProposableCommune
{
    /**
     * @param list<EventZone> $zones
     */
    public function __construct(
        public EventParticipation $participation,
        public array $zones,
    ) {
    }

    public function name(): string
    {
        return $this->participation->municipality()->name();
    }
}
