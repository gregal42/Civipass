<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Validity;

use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\DstChoice;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Event\Domain\Validity\ZoneValidityCalculator;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class ZoneValidityCalculatorTest extends TestCase
{
    private ZoneValidityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ZoneValidityCalculator();
    }

    /**
     * @return list<string> « début → fin » en UTC (fin « ∞ » si ouverte)
     */
    private static function describe(EffectivePeriods $periods): array
    {
        return array_map(
            static fn ($i): string => $i->start->format('Y-m-d H:i').' → '.($i->end?->format('Y-m-d H:i') ?? '∞'),
            $periods->intervals(),
        );
    }

    private static function june(): EventPeriod
    {
        return new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-07-01 00:00'));
    }

    private static function year(): EventPeriod
    {
        return new EventPeriod(T::utc('2026-01-01 00:00'), T::utc('2027-01-01 00:00'));
    }

    /**
     * @param list<ValiditySlot> $slots
     *
     * @return list<string>
     */
    private function specific(array $slots, ?EventPeriod $event = null, string $zone = 'Europe/Paris'): array
    {
        return self::describe($this->calculator->compute(ValidityMode::SpecificPeriods, $event ?? self::june(), T::zone($zone), $slots));
    }

    public function testUnconfiguredZoneGrantsNothing(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));

        self::assertTrue($this->calculator->compute(ValidityMode::Unconfigured, self::june(), T::paris(), [$slot])->isEmpty());
    }

    public function testEventDurationCoversTheWholeEventAndIgnoresSlots(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));

        $periods = $this->calculator->compute(ValidityMode::EventDuration, self::june(), T::paris(), [$slot]);

        self::assertSame(['2026-06-01 00:00 → 2026-07-01 00:00'], self::describe($periods));
        self::assertTrue($periods->isEffectiveAt(T::utc('2026-06-01 00:00')), 'début inclus');
        self::assertFalse($periods->isEffectiveAt(T::utc('2026-07-01 00:00')), 'fin exclue');
        self::assertFalse($periods->isEffectiveAt(T::utc('2026-05-31 23:59')));
    }

    public function testEventDurationWithoutEndIsOpenEnded(): void
    {
        $periods = $this->calculator->compute(ValidityMode::EventDuration, new EventPeriod(T::utc('2026-06-01 00:00')), T::paris());

        self::assertSame(['2026-06-01 00:00 → ∞'], self::describe($periods));
        self::assertTrue($periods->isEffectiveAt(T::utc('2099-01-01 00:00')));
    }

    public function testContinuousSlotIsConvertedToUtc(): void
    {
        $slot = ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-12'), T::time('18:00'));

        self::assertSame(['2026-06-10 06:00 → 2026-06-12 16:00'], $this->specific([$slot]));
    }

    public function testPunctualSlot(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));

        self::assertSame(['2026-06-10 07:00 → 2026-06-10 15:00'], $this->specific([$slot]));
    }

    public function testWeeklyRecurrence(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-14'), Weekdays::fromIsoDays([1, 3]), T::time('08:00'), T::time('12:00'));

        self::assertSame([
            '2026-06-01 06:00 → 2026-06-01 10:00',
            '2026-06-03 06:00 → 2026-06-03 10:00',
            '2026-06-08 06:00 → 2026-06-08 10:00',
            '2026-06-10 06:00 → 2026-06-10 10:00',
        ], $this->specific([$slot]));
    }

    public function testRecurrenceWithoutAnySelectedDayInThePeriodGrantsNothing(): void
    {
        // 1er juin 2026 = lundi ; la période couvre lundi à mercredi ; jour sélectionné : dimanche.
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-03'), Weekdays::fromIsoDays([7]), T::time('08:00'), T::time('12:00'));

        self::assertSame([], $this->specific([$slot]));
    }

    public function testRecurrenceCrossingMidnight(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-05'), T::date('2026-06-05'), Weekdays::fromIsoDays([5]), T::time('22:00'), T::time('06:00'));

        self::assertSame(['2026-06-05 20:00 → 2026-06-06 04:00'], $this->specific([$slot]));
    }

    public function testOverlappingAndContiguousSlotsAreMergedIntoAUnion(): void
    {
        $morning = ValiditySlot::punctual(T::date('2026-06-10'), T::time('08:00'), T::time('12:00'));
        $overlap = ValiditySlot::punctual(T::date('2026-06-10'), T::time('10:00'), T::time('14:00'));
        $nested = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('10:00'));
        $contiguous = ValiditySlot::punctual(T::date('2026-06-10'), T::time('14:00'), T::time('15:00'));
        $otherDay = ValiditySlot::punctual(T::date('2026-06-11'), T::time('08:00'), T::time('09:00'));

        self::assertSame([
            '2026-06-10 06:00 → 2026-06-10 13:00',
            '2026-06-11 06:00 → 2026-06-11 07:00',
        ], $this->specific([$otherDay, $contiguous, $nested, $overlap, $morning]));
    }

    public function testRecurrenceOverlappingAContinuousSlot(): void
    {
        $continuous = ValiditySlot::continuous(T::date('2026-06-01'), T::time('00:00'), T::date('2026-06-03'), T::time('12:00'));
        $recurring = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-07'), Weekdays::fromMask(127), T::time('10:00'), T::time('14:00'));

        self::assertSame([
            '2026-05-31 22:00 → 2026-06-03 12:00',
            '2026-06-04 08:00 → 2026-06-04 12:00',
            '2026-06-05 08:00 → 2026-06-05 12:00',
            '2026-06-06 08:00 → 2026-06-06 12:00',
            '2026-06-07 08:00 → 2026-06-07 12:00',
        ], $this->specific([$continuous, $recurring], new EventPeriod(T::utc('2026-05-31 22:00'), T::utc('2026-07-01 00:00'))));
    }

    public function testDaysAcrossDaylightSavingTransitionsHaveTheirRealLength(): void
    {
        $night = static fn (string $day): ValiditySlot => ValiditySlot::recurring(T::date($day), T::date($day), Weekdays::fromMask(127), T::time('00:00'), T::time('06:00'));

        // Passage à l'heure d'été : 00:00–06:00 locales ne durent que 5 heures.
        self::assertSame(['2026-03-28 23:00 → 2026-03-29 04:00'], $this->specific([$night('2026-03-29')], self::year()));
        // Retour à l'heure d'hiver : 7 heures.
        self::assertSame(['2026-10-24 22:00 → 2026-10-25 05:00'], $this->specific([$night('2026-10-25')], self::year()));
    }

    public function testRepeatedHourFollowsTheChosenOccurrence(): void
    {
        $slot = static fn (Occurrence $start): ValiditySlot => ValiditySlot::punctual(T::date('2026-10-25'), T::time('02:30'), T::time('04:00'), new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, $start),
        ]));

        self::assertSame(['2026-10-25 00:30 → 2026-10-25 03:00'], $this->specific([$slot(Occurrence::First)], self::year()));
        self::assertSame(['2026-10-25 01:30 → 2026-10-25 03:00'], $this->specific([$slot(Occurrence::Second)], self::year()));
    }

    public function testInvalidSlotsFailClosedInsteadOfGrantingAccess(): void
    {
        $nonexistent = ValiditySlot::punctual(T::date('2026-03-29'), T::time('02:30'), T::time('04:00'));
        $ambiguous = ValiditySlot::punctual(T::date('2026-10-25'), T::time('02:30'), T::time('04:00'));

        foreach ([$nonexistent, $ambiguous] as $slot) {
            try {
                $this->specific([$slot], self::year());
                self::fail('Un créneau invalide doit faire échouer le calcul.');
            } catch (InvalidValiditySlot) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDeletedSlotsAreExcludedFromTheCalculation(): void
    {
        $kept = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('10:00'));
        $deleted = ValiditySlot::punctual(T::date('2026-06-11'), T::time('09:00'), T::time('10:00'))->markDeleted(T::utc('2026-06-02 08:00'));

        self::assertSame(['2026-06-10 07:00 → 2026-06-10 08:00'], $this->specific([$kept, $deleted]));
        self::assertSame([], $this->specific([$deleted]));
    }

    public function testDeletedSlotsAreNotInspectedEvenIfTheyBecameInvalid(): void
    {
        // Un ancien créneau conservé pour l'historique ne doit jamais bloquer le calcul.
        $history = ValiditySlot::punctual(T::date('2026-03-29'), T::time('02:30'), T::time('04:00'))->markDeleted(T::utc('2026-03-01 08:00'));
        $current = ValiditySlot::punctual(T::date('2026-03-29'), T::time('05:00'), T::time('06:00'));

        self::assertSame(['2026-03-29 03:00 → 2026-03-29 04:00'], $this->specific([$history, $current], self::year()));
    }

    public function testSlotsAreBoundedToTheEventPeriod(): void
    {
        $event = new EventPeriod(T::utc('2026-06-10 12:00'), T::utc('2026-06-20 12:00'));
        $startsBefore = ValiditySlot::continuous(T::date('2026-06-09'), T::time('08:00'), T::date('2026-06-11'), T::time('08:00'));
        $endsAfter = ValiditySlot::continuous(T::date('2026-06-19'), T::time('08:00'), T::date('2026-06-21'), T::time('08:00'));
        $outside = ValiditySlot::punctual(T::date('2026-06-01'), T::time('08:00'), T::time('09:00'));
        $inside = ValiditySlot::punctual(T::date('2026-06-15'), T::time('08:00'), T::time('09:00'));

        self::assertSame([
            '2026-06-10 12:00 → 2026-06-11 06:00',
            '2026-06-15 06:00 → 2026-06-15 07:00',
            '2026-06-19 06:00 → 2026-06-20 12:00',
        ], $this->specific([$outside, $endsAfter, $inside, $startsBefore], $event));
    }

    public function testSlotsAreNotBoundedAtTheEndWhenTheEventHasNoEnd(): void
    {
        $event = new EventPeriod(T::utc('2026-06-10 00:00'));
        $slot = ValiditySlot::continuous(T::date('2026-06-09'), T::time('08:00'), T::date('2026-12-31'), T::time('08:00'));

        self::assertSame(['2026-06-10 00:00 → 2026-12-31 07:00'], $this->specific([$slot], $event));
    }

    public function testReducingTheEventDurationTakesEffectImmediately(): void
    {
        $slot = ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-19'), T::time('08:00'));
        $instant = T::utc('2026-06-18 12:00');

        $before = $this->calculator->compute(ValidityMode::SpecificPeriods, new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-20 12:00')), T::paris(), [$slot]);
        $after = $this->calculator->compute(ValidityMode::SpecificPeriods, new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-15 00:00')), T::paris(), [$slot]);
        $duration = $this->calculator->compute(ValidityMode::EventDuration, new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-15 00:00')), T::paris());

        self::assertTrue($before->isEffectiveAt($instant));
        self::assertFalse($after->isEffectiveAt($instant));
        self::assertTrue($after->isEffectiveAt(T::utc('2026-06-14 12:00')));
        self::assertFalse($duration->isEffectiveAt($instant));
    }

    public function testExtendingTheEventFollowsEventDurationButNeverExtendsSpecificSlots(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));
        $short = new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-20 00:00'));
        $long = new EventPeriod(T::utc('2026-06-01 00:00'), T::utc('2026-06-30 00:00'));

        self::assertSame($this->specific([$slot], $short), $this->specific([$slot], $long));
        self::assertSame(['2026-06-01 00:00 → 2026-06-30 00:00'], self::describe($this->calculator->compute(ValidityMode::EventDuration, $long, T::paris())));
    }

    public function testSpecificPeriodsWithoutSlotGrantNothing(): void
    {
        self::assertSame([], $this->specific([]));
    }

    public function testOverseasTerritories(): void
    {
        $punctual = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));

        self::assertSame(['2026-06-10 05:00 → 2026-06-10 13:00'], $this->specific([$punctual], zone: 'Indian/Reunion'));
        self::assertSame(['2026-06-10 19:00 → 2026-06-11 03:00'], $this->specific([$punctual], zone: 'Pacific/Tahiti'));
        self::assertSame(['2026-06-10 18:30 → 2026-06-11 02:30'], $this->specific([$punctual], zone: 'Pacific/Marquesas'));
        self::assertSame(['2026-06-10 18:00 → 2026-06-11 02:00'], $this->specific([$punctual], zone: 'Pacific/Gambier'));
        self::assertSame(['2026-06-10 13:00 → 2026-06-10 21:00'], $this->specific([$punctual], zone: 'America/Guadeloupe'));
    }

    public function testOverseasOvernightRecurrenceCrossesTheUtcDate(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-05'), T::date('2026-06-05'), Weekdays::fromIsoDays([5]), T::time('22:00'), T::time('06:00'));

        // Nouvelle-Calédonie (UTC+11) : vendredi 22:00 → samedi 06:00 locaux.
        self::assertSame(['2026-06-05 11:00 → 2026-06-05 19:00'], $this->specific([$slot], zone: 'Pacific/Noumea'));
    }

    public function testNoGapOrRepeatOnParisTransitionDatesForOverseasZones(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-03-29'), T::time('02:30'), T::time('04:00'));

        self::assertSame(['2026-03-28 22:30 → 2026-03-29 00:00'], $this->specific([$slot], self::year(), 'Indian/Reunion'));
    }

    public function testSaintPierreEtMiquelonRepeatedHour(): void
    {
        $slot = static fn (Occurrence $start): ValiditySlot => ValiditySlot::punctual(T::date('2026-11-01'), T::time('01:30'), T::time('03:00'), new DstChoices([
            new DstChoice(T::date('2026-11-01'), ChoiceField::Start, $start),
        ]));

        // Fin 03:00 locales (UTC-3 après le retour à l'heure d'hiver) = 06:00 UTC.
        self::assertSame(['2026-11-01 03:30 → 2026-11-01 06:00'], $this->specific([$slot(Occurrence::First)], self::year(), 'America/Miquelon'));
        self::assertSame(['2026-11-01 04:30 → 2026-11-01 06:00'], $this->specific([$slot(Occurrence::Second)], self::year(), 'America/Miquelon'));
    }
}
