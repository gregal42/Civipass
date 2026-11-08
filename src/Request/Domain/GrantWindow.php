<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Validity\UtcInterval;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Période GLOBALE d'autorisation d'une approbation : `[from, until)` en UTC ; fin nulle = « jusqu'à clôture » (la fin effective
 * dépend alors de l'état courant de l'événement et n'est jamais copiée ici). Ce n'est ni la date de décision, ni les périodes
 * d'accès par zone : celles-ci s'obtiennent par intersection (GrantWindowCalculator::effectivePeriods).
 */
final readonly class GrantWindow
{
    public \DateTimeImmutable $from;
    public ?\DateTimeImmutable $until;

    public function __construct(public DurationMode $mode, \DateTimeImmutable $from, ?\DateTimeImmutable $until)
    {
        $utc = new \DateTimeZone('UTC');
        if (DurationMode::UntilClosure === $mode && null !== $until) {
            throw new DomainRuleViolation('Une autorisation « jusqu\'à clôture » n\'a pas de date de fin propre.');
        }
        if (DurationMode::UntilClosure !== $mode && null === $until) {
            throw new DomainRuleViolation('Cette durée d\'autorisation exige une date de fin.');
        }
        if (null !== $until && $until <= $from) {
            throw new DomainRuleViolation('La fin de l\'autorisation doit être postérieure à son début.');
        }
        $this->from = $from->setTimezone($utc);
        $this->until = $until?->setTimezone($utc);
    }

    public function toInterval(): UtcInterval
    {
        return new UtcInterval($this->from, $this->until);
    }
}
