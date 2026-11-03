<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\ParticipationStatus;

/** Zones d'une commune participante, dans l'ordre : la commune qui consulte d'abord, puis l'organisatrice, puis les autres. */
final readonly class ZoneGroup
{
    /**
     * @param list<ZoneView> $zones
     */
    public function __construct(
        public string $municipalityId,
        public string $municipalityName,
        public bool $own,
        public bool $organizer,
        public ParticipationStatus $participationStatus,
        public array $zones,
    ) {
    }
}
