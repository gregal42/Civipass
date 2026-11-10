<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\UtcInterval;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Calcul pur des fenêtres d'autorisation (DM-109) :
 * - `window()` : période globale à l'approbation (début = décision ; durées fixes en heures absolues ; fin personnalisée
 *   convertie par LocalTimeResolver et refusée au-delà de la fin prévue de l'événement) ;
 * - `effectivePeriods()` : droits effectifs d'UNE zone = fenêtre ∩ période de l'événement (clôture comprise) ∩ périodes de la
 *   zone, vides si la participation n'est plus opérationnelle. Évalués à la demande, jamais copiés ; aucune durée fixe n'est
 *   prolongée pour compenser les heures où la zone est fermée.
 */
final readonly class GrantWindowCalculator
{
    public function __construct(private LocalTimeResolver $resolver = new LocalTimeResolver())
    {
    }

    /**
     * @param \DateTimeImmutable|null $eventEndsAt fin prévue de l'événement (nulle : sans fin)
     *
     * @throws DomainRuleViolation durée personnalisée invalide (passée, hors événement, heure inexistante ou ambiguë)
     */
    public function window(ApprovalDuration $duration, \DateTimeImmutable $decidedAt, EventTimezone $timezone, ?\DateTimeImmutable $eventEndsAt): GrantWindow
    {
        $from = $decidedAt->setTimezone(new \DateTimeZone('UTC'));
        $until = match ($duration->mode) {
            DurationMode::UntilClosure => null,
            DurationMode::Custom => $this->customEnd($duration, $from, $timezone, $eventEndsAt),
            default => $from->setTimestamp($from->getTimestamp() + ($duration->mode->fixedHours() ?? 0) * 3600),
        };

        return new GrantWindow($duration->mode, $from, $until);
    }

    /**
     * @param GrantWindow|list<GrantWindow> $window une période, ou les périodes successives d'une autorisation (LOT 05.5.2)
     */
    public function effectivePeriods(GrantWindow|array $window, EffectivePeriods $zonePeriods, EventParticipation $participation): EffectivePeriods
    {
        if (!$participation->isOperational()) {
            return new EffectivePeriods([]);
        }

        return $this->scheduledPeriods($window, $zonePeriods, $participation->event());
    }

    /**
     * Périodes PRÉVUES d'une zone : fenêtre ∩ période de l'événement (clôture comprise) ∩ périodes de la zone, SANS tenir compte de l'état
     * opérationnel de la participation. `effectivePeriods()` en est le cas opérationnel (même calcul, un seul moteur) ; la vérification
     * publique s'en sert pour montrer ce qui était prévu même quand un droit est révoqué ou suspendu.
     */
    /**
     * @param GrantWindow|list<GrantWindow> $window une période, ou les périodes successives d'une autorisation (LOT 05.5.2) : le résultat est la réunion de
     *                                              « créneaux de la zone ∩ période ∩ événement » pour chaque période ; l'interruption entre deux périodes ne donne aucun droit
     */
    public function scheduledPeriods(GrantWindow|array $window, EffectivePeriods $zonePeriods, Event $event): EffectivePeriods
    {
        $operational = new UtcInterval($event->startsAt(), $event->endsAt());
        $closedAt = $event->closedAt();
        if (null !== $closedAt && $closedAt > $event->startsAt()) {
            $operational = $operational->intersect(new UtcInterval($event->startsAt(), $closedAt));
        }
        $intervals = [];
        foreach (\is_array($window) ? $window : [$window] as $period) {
            $bound = null === $operational ? null : $period->toInterval()->intersect($operational);
            if (null === $bound) {
                continue;
            }
            foreach ($zonePeriods->intervals() as $interval) {
                $effective = $interval->intersect($bound);
                if (null !== $effective) {
                    $intervals[] = $effective;
                }
            }
        }

        return new EffectivePeriods($intervals);
    }

    private function customEnd(ApprovalDuration $duration, \DateTimeImmutable $from, EventTimezone $timezone, ?\DateTimeImmutable $eventEndsAt): \DateTimeImmutable
    {
        if (null === $duration->endDate || null === $duration->endTime) {
            throw new DomainRuleViolation('Indiquez la date et l\'heure de fin de l\'autorisation.');
        }
        $until = $this->resolver->resolve($duration->endDate, $duration->endTime, $timezone, $duration->occurrence);
        if ($until <= $from) {
            throw new DomainRuleViolation('La fin de l\'autorisation doit être postérieure à la décision.');
        }
        if (null !== $eventEndsAt && $until > $eventEndsAt) {
            throw new DomainRuleViolation('La fin de l\'autorisation ne peut pas dépasser la fin prévue de l\'événement.');
        }

        return $until;
    }
}
