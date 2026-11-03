<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Heure civile sans fuseau, à la minute (colonne TIME, secondes nulles).
 */
final readonly class LocalTime
{
    private function __construct(public int $hour, public int $minute)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            throw new DomainRuleViolation(sprintf('Heure invalide : « %s » (format HH:MM attendu).', $value));
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    public function isBefore(self $other): bool
    {
        return $this->minutesSinceMidnight() < $other->minutesSinceMidnight();
    }

    public function equals(self $other): bool
    {
        return $this->hour === $other->hour && $this->minute === $other->minute;
    }

    public function toString(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function minutesSinceMidnight(): int
    {
        return $this->hour * 60 + $this->minute;
    }
}
