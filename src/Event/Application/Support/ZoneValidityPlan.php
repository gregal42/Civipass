<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValidityMode;

/** Différence entre l'existant et la configuration demandée ; `changed` = false : remplacement identique (DM-088). */
final readonly class ZoneValidityPlan
{
    /**
     * @param list<EventZoneValidity> $currentSlots créneaux non supprimés au moment du plan
     */
    public function __construct(
        public EventZone $zone,
        public ParsedValidity $target,
        public array $currentSlots,
        public bool $modeChanged,
        public bool $changed,
    ) {
    }

    /** Une configuration existante serait remplacée par une autre (DM-025) : mode déjà configuré et changement réel. */
    public function overwritesExistingConfiguration(): bool
    {
        return $this->changed && ValidityMode::Unconfigured !== $this->zone->validityMode();
    }
}
