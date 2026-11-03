<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Time;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalValueObjectsTest extends TestCase
{
    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDates(string $value): void
    {
        $this->expectException(DomainRuleViolation::class);
        LocalDate::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'jour inexistant' => ['2026-02-30'];
        yield 'mois inexistant' => ['2026-13-01'];
        yield 'format' => ['01/02/2026'];
        yield 'vide' => [''];
        yield 'avec heure' => ['2026-02-01 10:00'];
    }

    #[DataProvider('invalidTimes')]
    public function testRejectsInvalidTimes(string $value): void
    {
        $this->expectException(DomainRuleViolation::class);
        LocalTime::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimes(): iterable
    {
        yield '24h' => ['24:00'];
        yield 'minutes' => ['12:60'];
        yield 'sans zéro' => ['9:00'];
        yield 'secondes' => ['12:00:00'];
        yield 'vide' => [''];
    }

    public function testDateArithmeticAndWeekdays(): void
    {
        $date = LocalDate::fromString('2026-03-29');

        self::assertSame(7, $date->isoDayOfWeek(), 'dimanche');
        self::assertSame('2026-03-30', $date->addDays(1)->toString());
        self::assertSame('2026-02-28', LocalDate::fromString('2026-03-01')->addDays(-1)->toString());
        self::assertTrue($date->isBefore($date->addDays(1)));
        self::assertFalse($date->isBefore($date));
        self::assertTrue($date->equals(LocalDate::fromString('2026-03-29')));
        self::assertSame(1, LocalDate::fromString('2026-06-01')->isoDayOfWeek(), 'lundi');
    }

    public function testTimeComparison(): void
    {
        $morning = LocalTime::fromString('08:05');

        self::assertSame('08:05', (string) $morning);
        self::assertTrue($morning->isBefore(LocalTime::fromString('08:06')));
        self::assertFalse($morning->isBefore($morning));
        self::assertTrue($morning->equals(LocalTime::fromString('08:05')));
    }
}
