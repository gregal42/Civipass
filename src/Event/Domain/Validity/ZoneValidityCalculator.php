<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalTimeResolver;

/**
 * Calcule les périodes d'accès effectives d'une zone, à la demande (jamais copiées) :
 * - `UNCONFIGURED` : aucun droit (DM-019) ;
 * - `EVENT_DURATION` : toute la durée de l'événement, bornes comprises, donc elle suit ses modifications ;
 * - `SPECIFIC_PERIODS` : union des créneaux **non supprimés** (DM-018, DM-075), bornée à la durée de l'événement.
 *   Un créneau n'est jamais prolongé par un allongement de l'événement.
 *
 * Un créneau invalide dans le fuseau (heure inexistante, choix manquant ou obsolète) fait échouer le calcul
 * (InvalidValiditySlot) : l'appelant refuse l'accès plutôt que d'en accorder sur une donnée incohérente.
 * Ne gère ni le statut de l'événement, ni celui de la participation ou de la zone, ni la confirmation.
 */
final readonly class ZoneValidityCalculator
{
    public function __construct(
        private LocalTimeResolver $resolver = new LocalTimeResolver(),
        private SlotOccurrenceGenerator $generator = new SlotOccurrenceGenerator(),
        private SlotInspector $inspector = new SlotInspector(),
    ) {
    }

    /**
     * @param iterable<ValiditySlot> $slots
     *
     * @throws InvalidValiditySlot
     */
    public function compute(ValidityMode $mode, EventPeriod $event, EventTimezone $timezone, iterable $slots = []): EffectivePeriods
    {
        $bounds = $event->toInterval();

        if (ValidityMode::Unconfigured === $mode) {
            return new EffectivePeriods([]);
        }
        if (ValidityMode::EventDuration === $mode) {
            return new EffectivePeriods([$bounds]);
        }

        $intervals = [];
        foreach ($slots as $slot) {
            if ($slot->isDeleted()) {
                continue;
            }
            $this->inspector->assertValid($slot, $timezone);
            foreach ($this->generator->generate($slot) as $occurrence) {
                $start = $this->resolver->resolve($occurrence->startDate, $occurrence->startTime, $timezone, $slot->dstChoices->find($occurrence->startDate, ChoiceField::Start));
                $end = $this->resolver->resolve($occurrence->endDate, $occurrence->endTime, $timezone, $slot->dstChoices->find($occurrence->endDate, ChoiceField::End));
                $bounded = (new UtcInterval($start, $end))->intersect($bounds);
                if (null !== $bounded) {
                    $intervals[] = $bounded;
                }
            }
        }

        return new EffectivePeriods($intervals);
    }
}
