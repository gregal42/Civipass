<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Configuration complète de la validité d'une zone (remplacement). `mode` : EVENT_DURATION (aucun créneau) ou
 * SPECIFIC_PERIODS (au moins un créneau).
 */
final readonly class ZoneValidityCommand
{
    /**
     * @param list<ValiditySlotInput> $slots
     */
    public function __construct(
        public string $mode,
        public array $slots = [],
    ) {
    }
}
