<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

/**
 * Développe un créneau en occurrences locales. Une occurrence de récurrence est rattachée à son jour de début ;
 * si l'heure de fin est antérieure à l'heure de début, elle se termine le lendemain (passage de minuit).
 */
final readonly class SlotOccurrenceGenerator
{
    /**
     * @return list<SlotOccurrence>
     */
    public function generate(ValiditySlot $slot): array
    {
        return match ($slot->kind) {
            ValidityKind::Continuous => [new SlotOccurrence($slot->startDate, $slot->startTime, $slot->endDate ?? $slot->startDate, $slot->endTime)],
            ValidityKind::Punctual => [new SlotOccurrence($slot->startDate, $slot->startTime, $slot->startDate, $slot->endTime)],
            ValidityKind::Recurring => $this->recurring($slot),
        };
    }

    /**
     * @return list<SlotOccurrence>
     */
    private function recurring(ValiditySlot $slot): array
    {
        $weekdays = $slot->weekdays;
        $last = $slot->endDate;
        if (null === $weekdays || null === $last) {
            return [];
        }

        $crossesMidnight = $slot->endTime->isBefore($slot->startTime);
        $occurrences = [];
        for ($day = $slot->startDate; !$last->isBefore($day); $day = $day->addDays(1)) {
            if (!$weekdays->includes($day->isoDayOfWeek())) {
                continue;
            }
            $occurrences[] = new SlotOccurrence($day, $slot->startTime, $crossesMidnight ? $day->addDays(1) : $day, $slot->endTime);
        }

        return $occurrences;
    }
}
