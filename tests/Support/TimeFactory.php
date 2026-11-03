<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;

/**
 * Raccourcis de lecture pour les tests du domaine temporel.
 */
final class TimeFactory
{
    public static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function date(string $value): LocalDate
    {
        return LocalDate::fromString($value);
    }

    public static function time(string $value): LocalTime
    {
        return LocalTime::fromString($value);
    }

    public static function zone(string $identifier): EventTimezone
    {
        return EventTimezone::fromIdentifier($identifier);
    }

    public static function paris(): EventTimezone
    {
        return self::zone('Europe/Paris');
    }
}
