<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Validity;

use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\UtcInterval;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class EffectivePeriodsTest extends TestCase
{
    private static function interval(string $start, ?string $end): UtcInterval
    {
        return new UtcInterval(T::utc($start), null === $end ? null : T::utc($end));
    }

    public function testIntervalsAreSortedAndMerged(): void
    {
        $periods = new EffectivePeriods([
            self::interval('2026-06-03 00:00', '2026-06-04 00:00'),
            self::interval('2026-06-01 00:00', '2026-06-02 00:00'),
            self::interval('2026-06-02 00:00', '2026-06-02 12:00'),
            self::interval('2026-06-01 06:00', '2026-06-01 08:00'),
        ]);

        self::assertCount(2, $periods->intervals());
        self::assertEquals(T::utc('2026-06-02 12:00'), $periods->intervals()[0]->end);
        self::assertEquals(T::utc('2026-06-03 00:00'), $periods->intervals()[1]->start);
    }

    public function testAnOpenEndedIntervalAbsorbsTheFollowingOnes(): void
    {
        $periods = new EffectivePeriods([
            self::interval('2026-06-05 00:00', '2026-06-06 00:00'),
            self::interval('2026-06-01 00:00', null),
        ]);

        self::assertCount(1, $periods->intervals());
        self::assertNull($periods->intervals()[0]->end);
    }

    public function testHalfOpenBoundaries(): void
    {
        $periods = new EffectivePeriods([self::interval('2026-06-01 10:00', '2026-06-01 12:00')]);

        self::assertFalse($periods->isEffectiveAt(T::utc('2026-06-01 09:59:59')));
        self::assertTrue($periods->isEffectiveAt(T::utc('2026-06-01 10:00')));
        self::assertTrue($periods->isEffectiveAt(T::utc('2026-06-01 11:59:59')));
        self::assertFalse($periods->isEffectiveAt(T::utc('2026-06-01 12:00')));
        self::assertFalse((new EffectivePeriods([]))->isEffectiveAt(T::utc('2026-06-01 11:00')));
        self::assertTrue((new EffectivePeriods([]))->isEmpty());
    }

    public function testIntervalRefusesAnEndNotAfterItsStart(): void
    {
        $this->expectException(DomainRuleViolation::class);
        self::interval('2026-06-01 10:00', '2026-06-01 10:00');
    }

    public function testIntersection(): void
    {
        $a = self::interval('2026-06-01 00:00', '2026-06-10 00:00');

        self::assertEquals(self::interval('2026-06-05 00:00', '2026-06-10 00:00'), $a->intersect(self::interval('2026-06-05 00:00', null)));
        self::assertNull($a->intersect(self::interval('2026-06-10 00:00', '2026-06-11 00:00')), 'contigus mais disjoints');
        self::assertNull($a->intersect(self::interval('2026-05-01 00:00', '2026-06-01 00:00')));
    }

    public function testEventPeriodValidation(): void
    {
        self::assertNull((new EventPeriod(T::utc('2026-06-01 00:00')))->endsAt);

        $this->expectException(DomainRuleViolation::class);
        new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-01 00:00'));
    }
}
