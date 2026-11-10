<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Service\ZoneEffectivePeriodsResolver;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\UtcInterval;
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

    /**
     * @param bool $forVerification mode de la vérification publique (LOT 05.4) : ajoute les périodes PRÉVUES de chaque zone et les zones retirées
     *                              (état REMOVED). Faux (défaut, tous les autres appelants) : résultat strictement inchangé.
     */
    public function evaluate(Permit $permit, \DateTimeImmutable $at, bool $forVerification = false): PermitRights
    {
        $authorizations = array_map(fn (PermitAuthorization $a): AuthorizationRights => $this->authorization($a, $at, $forVerification), $permit->authorizations());
        usort($authorizations, static fn (AuthorizationRights $a, AuthorizationRights $b): int => [$a->municipalityName, $a->authorizationId->toRfc4122()] <=> [$b->municipalityName, $b->authorizationId->toRfc4122()]);

        return new PermitRights($permit->id(), $permit->reference()->value, $permit->event()->timezone()->identifier(), $at, $authorizations);
    }

    private function authorization(PermitAuthorization $authorization, \DateTimeImmutable $at, bool $forVerification): AuthorizationRights
    {
        $window = $authorization->window();
        $common = $this->commonState($authorization);
        $rights = [];
        foreach ($authorization->activeZones() as $zone) {
            if (null !== $common) {
                $right = new ZoneRight($zone->zone()->id(), $zone->zoneLabel(), $common);
                $rights[] = $forVerification ? $right->withPeriods($this->scheduled($authorization, $zone)) : $right;
            } else {
                $rights[] = $this->zone($authorization, $zone, $at, $forVerification);
            }
        }
        if ($forVerification) {
            // Retrait d'une zone : fait distinct de la révocation de l'autorisation, conservé même si celle-ci est révoquée ensuite.
            foreach ($authorization->removedZones() as $removed) {
                $rights[] = new ZoneRight($removed->zone()->id(), $removed->zoneLabel(), RightState::Removed, removedAt: $removed->removedAt());
            }
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

    /**
     * Périodes PRÉVUES d'une zone (fenêtre ∩ événement ∩ créneaux de la zone), sans égard à l'état : même moteur que les périodes effectives.
     *
     * @return list<UtcInterval>
     */
    private function scheduled(PermitAuthorization $authorization, PermitAuthorizationZone $copied): array
    {
        $event = $authorization->participation()->event();

        // Périodes PRÉVUES affichées au contrôle : celles de la période COURANTE seulement (l'historique des reprises n'est pas exposé).
        return $this->windows->scheduledPeriods($authorization->window(), $this->periods->periods($copied->zone(), new EventPeriod($event->startsAt(), $event->endsAt())), $event)->intervals();
    }

    private function zone(PermitAuthorization $authorization, PermitAuthorizationZone $copied, \DateTimeImmutable $at, bool $forVerification): ZoneRight
    {
        $zone = $copied->zone();
        if (!$zone->mayOpenRights()) {
            $suspended = new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::ZoneSuspended);

            return $forVerification ? $suspended->withPeriods($this->scheduled($authorization, $copied)) : $suspended;
        }
        $participation = $authorization->participation();
        $event = $participation->event();
        $window = $authorization->window();
        $zonePeriods = $this->periods->periods($zone, new EventPeriod($event->startsAt(), $event->endsAt()));
        // Période COURANTE : c'est la seule listée au contrôle public (l'historique des reprises n'est pas exposé) et, tant qu'elle a commencé, la seule où le droit
        // peut être valide maintenant (les périodes closes finissent avant son début). Dans ce cas courant, les périodes closes ne sont jamais lues : le coût d'un
        // contrôle ne dépend pas de la taille de l'historique.
        $current = $this->windows->effectivePeriods($window, $zonePeriods, $participation);
        $periods = $forVerification ? $current->intervals() : [];
        if ($at >= $window->from) {
            foreach ($current->intervals() as $interval) {
                if ($interval->contains($at)) {
                    return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::Valid, $interval->start, $interval->end, periods: $periods);
                }
            }
        }
        // Sinon l'ÉTAT (hors période, à venir, expiré) tient compte de TOUTES les périodes : une période passée suffit à distinguer « hors période » de « à venir ».
        $windows = $authorization->windows();
        $effective = \count($windows) > 1 ? $this->windows->effectivePeriods($windows, $zonePeriods, $participation) : $current;
        $past = false;
        $next = null;
        foreach ($effective->intervals() as $interval) {
            if ($interval->contains($at)) {
                return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::Valid, $interval->start, $interval->end, periods: $periods);
            }
            if ($interval->start > $at) {
                $next ??= $interval->start;
                $next = min($next, $interval->start);
            } else {
                $past = true;
            }
        }
        if (null !== $next) {
            return new ZoneRight($zone->id(), $copied->zoneLabel(), $past ? RightState::OutsidePeriod : RightState::NotYetValid, nextStart: $next, periods: $periods);
        }
        // Après la fin de la DERNIÈRE période (la courante) : expiré. Toutes les périodes sont passées.
        if ($past || (null !== $window->until && $at >= $window->until)) {
            return new ZoneRight($zone->id(), $copied->zoneLabel(), RightState::Expired, periods: $periods);
        }

        // Dans la fenêtre mais sans aucune période d'accès (créneaux supprimés depuis la décision, par exemple).
        return new ZoneRight($zone->id(), $copied->zoneLabel(), $at < $windows[0]->from ? RightState::NotYetValid : RightState::OutsidePeriod, periods: $periods);
    }
}
