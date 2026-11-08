<?php

declare(strict_types=1);

namespace App\Request\Domain;

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

    public function effectivePeriods(GrantWindow $window, EffectivePeriods $zonePeriods, EventParticipation $participation): EffectivePeriods
    {
        if (!$participation->isOperational()) {
            return new EffectivePeriods([]);
        }
        $event = $participation->event();
        $operational = new UtcInterval($event->startsAt(), $event->endsAt());
        $closedAt = $event->closedAt();
        if (null !== $closedAt && $closedAt > $event->startsAt()) {
            $operational = $operational->intersect(new UtcInterval($event->startsAt(), $closedAt));
        }
        $bound = null === $operational ? null : $window->toInterval()->intersect($operational);
        $intervals = [];
        if (null !== $bound) {
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
