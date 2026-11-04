<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\Validity\ValidityKind;

/**
 * Créneau de validité tel que les écrans le lisent, en horaires LOCAUX du fuseau de l'événement (jamais d'UTC) : dates `AAAA-MM-JJ`,
 * heures `HH:MM`, jours ISO (1 = lundi … 7 = dimanche) pour une récurrence, choix d'occurrence horaire enregistrés.
 */
final readonly class SlotView
{
    /**
     * @param list<int>                                                    $isoWeekdays
     * @param list<array{date: string, field: string, occurrence: string}> $dstChoices
     */
    public function __construct(
        public string $slotId,
        public ValidityKind $kind,
        public string $startDate,
        public string $startTime,
        public ?string $endDate,
        public string $endTime,
        public array $isoWeekdays = [],
        public array $dstChoices = [],
    ) {
    }
}
