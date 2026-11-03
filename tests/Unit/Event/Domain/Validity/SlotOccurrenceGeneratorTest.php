<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Validity;

use App\Event\Domain\Validity\SlotOccurrence;
use App\Event\Domain\Validity\SlotOccurrenceGenerator;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class SlotOccurrenceGeneratorTest extends TestCase
{
    /**
     * @param list<SlotOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function describe(array $occurrences): array
    {
        return array_map(static fn (SlotOccurrence $o): string => sprintf('%s %s → %s %s', $o->startDate, $o->startTime, $o->endDate, $o->endTime), $occurrences);
    }

    public function testContinuousAndPunctualSlotsHaveOneOccurrence(): void
    {
        $generator = new SlotOccurrenceGenerator();

        self::assertSame(
            ['2026-06-10 08:00 → 2026-06-12 18:00'],
            self::describe($generator->generate(ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-12'), T::time('18:00')))),
        );
        self::assertSame(
            ['2026-06-10 09:00 → 2026-06-10 17:00'],
            self::describe($generator->generate(ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00')))),
        );
    }

    public function testWeeklyRecurrenceKeepsOnlySelectedDays(): void
    {
        // 1er juin 2026 = lundi.
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-14'), Weekdays::fromIsoDays([1, 3]), T::time('08:00'), T::time('12:00'));

        self::assertSame([
            '2026-06-01 08:00 → 2026-06-01 12:00',
            '2026-06-03 08:00 → 2026-06-03 12:00',
            '2026-06-08 08:00 → 2026-06-08 12:00',
            '2026-06-10 08:00 → 2026-06-10 12:00',
        ], self::describe((new SlotOccurrenceGenerator())->generate($slot)));
    }

    public function testOvernightRecurrenceEndsTheNextDayAndIsAttachedToItsStartDay(): void
    {
        // Le dernier jour de la période démarre une occurrence qui se termine le lendemain, hors période.
        $slot = ValiditySlot::recurring(T::date('2026-06-05'), T::date('2026-06-12'), Weekdays::fromIsoDays([5]), T::time('22:00'), T::time('06:00'));

        self::assertSame([
            '2026-06-05 22:00 → 2026-06-06 06:00',
            '2026-06-12 22:00 → 2026-06-13 06:00',
        ], self::describe((new SlotOccurrenceGenerator())->generate($slot)));
    }

    public function testDailyRecurrenceOverAMonth(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-30'), Weekdays::fromMask(127), T::time('08:00'), T::time('12:00'));

        self::assertCount(30, (new SlotOccurrenceGenerator())->generate($slot));
    }

    public function testRecurrenceWithoutMatchingDayHasNoOccurrence(): void
    {
        // 1er juin 2026 = lundi ; période de lundi à mercredi, jour sélectionné : dimanche.
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-03'), Weekdays::fromIsoDays([7]), T::time('08:00'), T::time('12:00'));

        self::assertSame([], (new SlotOccurrenceGenerator())->generate($slot));
    }
}
