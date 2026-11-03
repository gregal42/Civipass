<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Durée d'un événement : début en UTC, fin facultative (sans fin : borne ouverte).
 */
final readonly class EventPeriod
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt = null,
    ) {
        if (null !== $endsAt && $endsAt <= $startsAt) {
            throw new DomainRuleViolation('La fin de l\'événement doit être postérieure à son début.');
        }
    }

    public function toInterval(): UtcInterval
    {
        return new UtcInterval($this->startsAt, $this->endsAt);
    }
}
