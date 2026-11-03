<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Créneau saisi, en heures LOCALES du fuseau de l'événement (jamais d'UTC). Données brutes : le service construit et
 * inspecte le ValiditySlot du domaine temporel.
 *
 * `kind` : CONTINUOUS | PUNCTUAL | RECURRING ; dates `AAAA-MM-JJ`, heures `HH:MM` ; `endDate` obligatoire pour CONTINUOUS et
 * RECURRING ; `isoWeekdays` (1 = lundi … 7 = dimanche) pour RECURRING ; `dstChoices` : [{date, field: start|end, occurrence: first|second}].
 */
final readonly class ValiditySlotInput
{
    /**
     * @param list<int>                                                    $isoWeekdays
     * @param list<array{date: string, field: string, occurrence: string}> $dstChoices
     */
    public function __construct(
        public string $kind,
        public string $startDate,
        public string $startTime,
        public string $endTime,
        public ?string $endDate = null,
        public array $isoWeekdays = [],
        public array $dstChoices = [],
    ) {
    }
}
