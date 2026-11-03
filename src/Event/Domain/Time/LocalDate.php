<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Date civile sans fuseau (colonne DATE).
 */
final readonly class LocalDate
{
    private function __construct(private \DateTimeImmutable $date)
    {
    }

    public static function fromString(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new DomainRuleViolation(sprintf('Date invalide : « %s » (format AAAA-MM-JJ attendu).', $value));
        }

        return new self($date);
    }

    public function addDays(int $days): self
    {
        return new self($this->date->modify(sprintf('%+d days', $days)));
    }

    /** 1 = lundi … 7 = dimanche. */
    public function isoDayOfWeek(): int
    {
        return (int) $this->date->format('N');
    }

    public function isBefore(self $other): bool
    {
        return $this->date < $other->date;
    }

    public function equals(self $other): bool
    {
        return $this->date == $other->date;
    }

    /** Minuit de cette date vu comme heure « murale » étiquetée UTC (sert au calcul des décalages). */
    public function toWallClock(LocalTime $time): \DateTimeImmutable
    {
        return $this->date->setTime($time->hour, $time->minute);
    }

    public function toString(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
