<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ZoneValidityCalculator;

/**
 * Périodes d'accès d'une zone (avant intersection avec la fenêtre d'une autorisation) : mode de validité et créneaux ACTIFS de la zone,
 * calculés dans le fuseau de l'événement. Extrait SANS changement de comportement de MunicipalDecisionApplier (LOT 05.1) pour que la
 * décision (LOT 04) et l'évaluation des droits (PermitRightsEvaluator) partagent UN SEUL moteur (DT-047). Lecture seule.
 */
final readonly class ZoneEffectivePeriodsResolver
{
    public function __construct(
        private EventZoneValidityRepository $validities,
        private ZoneValidityCalculator $calculator = new ZoneValidityCalculator(),
    ) {
    }

    public function periods(EventZone $zone, EventPeriod $period): EffectivePeriods
    {
        $slots = array_map(static fn ($validity) => $validity->toSlot(), $this->validities->findActiveByZone($zone->id()));
        try {
            return $this->calculator->compute($zone->validityMode(), $period, $zone->participation()->event()->timezone(), $slots);
        } catch (InvalidValiditySlot) {
            return new EffectivePeriods([]); // donnée incohérente : aucun droit n'est accordé
        }
    }
}
