<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\ZoneValidityCalculator;
use App\Request\Application\Result\ProposableCommune;

/**
 * Zones « ouvertes aux demandes » (DM-104) : source unique du formulaire public ET du dépôt. Une zone est proposable si
 * `EventZone::mayOpenRights()` (confirmée, active, non supprimée, validité configurée, participation opérationnelle) et,
 * en « périodes spécifiques », si au moins un créneau non supprimé est cohérent et pas entièrement expiré au moment
 * considéré (un créneau futur suffit ; périodes bornées à la durée de l'événement par le calculateur existant).
 * Une commune invitée, suspendue ou clôturée n'a aucune zone proposable.
 */
final readonly class ProposableZones
{
    public function __construct(
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private EventZoneValidityRepository $validities,
        private ZoneValidityCalculator $calculator = new ZoneValidityCalculator(),
    ) {
    }

    /**
     * Communes (participation opérationnelle) ayant au moins une zone proposable, par nom, zones par libellé.
     *
     * @return list<ProposableCommune>
     */
    public function forEvent(Event $event, \DateTimeImmutable $now): array
    {
        if (!$event->isOperational()) {
            return [];
        }
        $candidates = [];
        foreach ($this->participations->findByEvent($event->id()) as $participation) {
            if (!$participation->isOperational()) {
                continue;
            }
            $zones = array_values(array_filter($this->zones->findActiveByParticipation($participation->id()), static fn (EventZone $z): bool => $z->mayOpenRights()));
            if ([] !== $zones) {
                $candidates[] = [$participation, $zones];
            }
        }
        $all = [];
        foreach ($candidates as [, $zones]) {
            $all = [...$all, ...$zones];
        }
        $usable = [];
        foreach ($this->withUsablePeriods($all, $event, $now) as $zone) {
            $usable[$zone->id()->toRfc4122()] = true;
        }
        $communes = [];
        foreach ($candidates as [$participation, $zones]) {
            $kept = array_values(array_filter($zones, static fn (EventZone $z): bool => isset($usable[$z->id()->toRfc4122()])));
            usort($kept, static fn (EventZone $a, EventZone $b): int => strcmp($a->label(), $b->label()));
            if ([] !== $kept) {
                $communes[] = new ProposableCommune($participation, $kept);
            }
        }
        usort($communes, static fn (ProposableCommune $a, ProposableCommune $b): int => strcmp($a->name(), $b->name()));

        return $communes;
    }

    /**
     * Ne garde que les zones utilisables : celles qui ne sont pas en « périodes spécifiques » sont conservées telles quelles ;
     * les autres exigent au moins un créneau non supprimé, cohérent et non entièrement expiré. Créneau par créneau : un
     * créneau incohérent est ignoré sans condamner les autres.
     *
     * @param list<EventZone> $zones
     *
     * @return list<EventZone>
     */
    public function withUsablePeriods(array $zones, Event $event, \DateTimeImmutable $now): array
    {
        $periodZoneIds = [];
        foreach ($zones as $zone) {
            if (ValidityMode::SpecificPeriods === $zone->validityMode()) {
                $periodZoneIds[] = $zone->id();
            }
        }
        if ([] === $periodZoneIds) {
            return $zones;
        }
        $slotsByZone = [];
        foreach ($this->validities->findActiveByZones($periodZoneIds) as $slot) {
            $slotsByZone[$slot->zone()->id()->toRfc4122()][] = $slot->toSlot();
        }
        $period = new EventPeriod($event->startsAt(), $event->endsAt());

        return array_values(array_filter($zones, fn (EventZone $z): bool => ValidityMode::SpecificPeriods !== $z->validityMode()
            || $this->hasUnexpiredPeriod($slotsByZone[$z->id()->toRfc4122()] ?? [], $period, $event, $now)));
    }

    /**
     * @param list<ValiditySlot> $slots
     */
    private function hasUnexpiredPeriod(array $slots, EventPeriod $period, Event $event, \DateTimeImmutable $now): bool
    {
        foreach ($slots as $slot) {
            try {
                $effective = $this->calculator->compute(ValidityMode::SpecificPeriods, $period, $event->timezone(), [$slot]);
            } catch (InvalidValiditySlot) {
                continue;
            }
            foreach ($effective->intervals() as $interval) {
                if (null === $interval->end || $interval->end > $now) {
                    return true;
                }
            }
        }

        return false;
    }
}
