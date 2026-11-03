<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Intervalle d'instants UTC semi-ouvert [début, fin) ; fin nulle = sans borne de fin.
 */
final readonly class UtcInterval
{
    public \DateTimeImmutable $start;
    public ?\DateTimeImmutable $end;

    public function __construct(\DateTimeImmutable $start, ?\DateTimeImmutable $end)
    {
        if (null !== $end && $end <= $start) {
            throw new DomainRuleViolation('Un intervalle doit se terminer strictement après son début.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end?->setTimezone($utc);
    }

    public function contains(\DateTimeImmutable $instant): bool
    {
        return $instant >= $this->start && (null === $this->end || $instant < $this->end);
    }

    /** Intersection, ou null si elle est vide. */
    public function intersect(self $other): ?self
    {
        $start = max($this->start, $other->start);
        $end = match (true) {
            null === $this->end => $other->end,
            null === $other->end => $this->end,
            default => min($this->end, $other->end),
        };

        return null === $end || $end > $start ? new self($start, $end) : null;
    }
}
