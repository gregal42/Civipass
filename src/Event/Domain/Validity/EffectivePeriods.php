<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

/**
 * Périodes d'accès effectives d'une zone : union triée d'intervalles disjoints (chevauchements et
 * intervalles contigus fusionnés, DM-018).
 */
final readonly class EffectivePeriods
{
    /** @var list<UtcInterval> */
    private array $intervals;

    /**
     * @param list<UtcInterval> $intervals
     */
    public function __construct(array $intervals)
    {
        usort($intervals, static fn (UtcInterval $a, UtcInterval $b): int => $a->start <=> $b->start);

        $merged = [];
        foreach ($intervals as $interval) {
            $last = $merged[\count($merged) - 1] ?? null;
            if (null !== $last && (null === $last->end || $interval->start <= $last->end)) {
                $end = (null === $last->end || null === $interval->end) ? null : max($last->end, $interval->end);
                $merged[\count($merged) - 1] = new UtcInterval($last->start, $end);
                continue;
            }
            $merged[] = $interval;
        }
        $this->intervals = $merged;
    }

    /**
     * @return list<UtcInterval>
     */
    public function intervals(): array
    {
        return $this->intervals;
    }

    public function isEmpty(): bool
    {
        return [] === $this->intervals;
    }

    public function isEffectiveAt(\DateTimeImmutable $instant): bool
    {
        foreach ($this->intervals as $interval) {
            if ($interval->contains($instant)) {
                return true;
            }
        }

        return false;
    }
}
