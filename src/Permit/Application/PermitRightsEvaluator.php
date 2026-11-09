<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Service\ZoneEffectivePeriodsResolver;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Validity\EventPeriod;
use App\Municipality\Domain\MunicipalityStatus;
use App\Permit\Domain\AuthorizationRights;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitAuthorization;
use App\Permit\Domain\PermitAuthorizationZone;
use App\Permit\Domain\PermitRights;
use App\Permit\Domain\RightState;
use App\Permit\Domain\ZoneRight;
use App\Request\Domain\GrantWindowCalculator;

/**
 * Droits effectifs d'un laissez-passer à un instant donné (LOT 05.1, DM-112, DT-047) : calcul UNIQUE, en lecture seule, qui ne stocke
 * aucun état de validité. Il réutilise les calculateurs du LOT 04 (fenêtre ∩ événement ∩ périodes de la zone) sans les dupliquer.
 *
 * Pour chaque zone de chaque autorisation, dans l'ordre : autorisation révoquée ; commune non ACTIVE (DM-035, absent de
 * `effectivePeriods`) ; événement clôturé puis non actif ; participation non ACTIVE ; zone non ouvrable ; enfin l'état temporel. Ce
 * calcul porte sur l'état COURANT : une zone suspendue ou une commune devenue inactive depuis la décision n'ouvre plus de droit, alors que
 * la décision avait exigé une intersection non vide AU MOMENT de décider (différence assumée, DT-047).
 *
 * L'instant est en UTC ; la présentation (fuseau de l'événement) relève de l'appelant, qui trouve le fuseau dans `PermitRights::$timezone`.
 */
final readonly class PermitRightsEvaluator
{
    public function __construct(
        private ZoneEffectivePeriodsResolver $periods,
        private GrantWindowCalculator $windows = new GrantWindowCalculator(),
    ) {
    }

    public function evaluate(Permit $permit, \DateTimeImmutable $at): PermitRights
    {
        $authorizations = array_map(fn (PermitAuthorization $a): AuthorizationRights => $this->authorization($a, $at), $permit->authorizations());
        usort($authorizations, static fn (AuthorizationRights $a, AuthorizationRights $b): int => [$a->municipalityName, $a->authorizationId->toRfc4122()] <=> [$b->municipalityName, $b->authorizationId->toRfc4122()]);

        return new PermitRights($permit->id(), $permit->reference()->value, $permit->event()->timezone()->identifier(), $at, $authorizations);
    }

    private function authorization(PermitAuthorization $authorization, \DateTimeImmutable $at): AuthorizationRights
    {
        $window = $authorization->window();
        $common = $this->commonState($authorization);
        $rights = [];
        foreach ($authorization->activeZones() as $zone) {
            $rights[] = null !== $common ? new ZoneRight($zone->zone()->id(), $zone->zoneLabel(), $common) : $this->zone($authorization, $zone, $at);
        }

        return new AuthorizationRights(
            $authorization->id(),
            $authorization->municipality()->id(),
            $authorization->municipality()->name(),
            $window->mode,
            $window->from,
            $window->until,
            $authorization->revokedAt(),
            $rights,
        );
    }

    /** Cause qui rend TOUTES les zones de l'autorisation inopérantes, ou nul. */
    private function commonState(PermitAuthorization $authorization): ?RightState
    {
        $participation = $authorization->participation();
        $event = $participation->event();

        return match (true) {
            $authorization->isRevoked() => RightState::Revoked,
            MunicipalityStatus::Active !== $authorization->municipality()->status() => RightState::CommuneInactive,
            EventStatus::Closed === $event->status() => RightState::EventClosed,
            EventStatus::Active !== $event->status() => RightState::EventNotActive,
            ParticipationStatus::Active !== $participation->status() => RightState::ParticipationInactive,
            default => null,
        };
    }

    private function zone(PermitAuthorization $authorization, PermitAuthorizationZone $copied, \DateTimeImmutable $at): ZoneRight
    {
        $zone = $copied->zone();
        if (!$zone->mayOpenRights()) {
            return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::ZoneSuspended);
        }
        $participation = $authorization->participation();
        $event = $participation->event();
        $window = $authorization->window();
        $effective = $this->windows->effectivePeriods($window, $this->periods->periods($zone, new EventPeriod($event->startsAt(), $event->endsAt())), $participation);

        $past = false;
        $next = null;
        foreach ($effective->intervals() as $interval) {
            if ($interval->contains($at)) {
                return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::Valid, $interval->start, $interval->end);
            }
            if ($interval->start > $at) {
                $next ??= $interval->start;
                $next = min($next, $interval->start);
            } else {
                $past = true;
            }
        }
        if (null !== $next) {
            return new ZoneRight($zone->id(), $copied->zoneLabel(), $past ? RightState::OutsidePeriod : RightState::NotYetValid, nextStart: $next);
        }
        if ($past || (null !== $window->until && $at >= $window->until)) {
            return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::Expired);
        }

        // Dans la fenêtre mais sans aucune période d'accès (créneaux supprimés depuis la décision, par exemple).
        return new ZoneRight($zone->id(), $copied->zoneLabel(), $at < $window->from ? RightState::NotYetValid : RightState::OutsidePeriod);
    }
}
