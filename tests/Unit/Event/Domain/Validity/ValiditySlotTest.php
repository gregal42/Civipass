<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Validity;

use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\DstChoice;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class ValiditySlotTest extends TestCase
{
    public function testContinuousSlot(): void
    {
        $slot = ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-12'), T::time('18:00'));

        self::assertSame(ValidityKind::Continuous, $slot->kind);
        self::assertFalse($slot->isDeleted());
    }

    public function testContinuousSlotMayEndTheNextDayEarlierInTheDay(): void
    {
        $slot = ValiditySlot::continuous(T::date('2026-06-10'), T::time('22:00'), T::date('2026-06-11'), T::time('06:00'));

        self::assertSame(ValidityKind::Continuous, $slot->kind);
    }

    public function testContinuousSlotEndMustBeStrictlyAfterStart(): void
    {
        foreach ([
            [T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-10'), T::time('08:00')],
            [T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-10'), T::time('07:00')],
            [T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-09'), T::time('18:00')],
        ] as [$sd, $st, $ed, $et]) {
            try {
                ValiditySlot::continuous($sd, $st, $ed, $et);
                self::fail('Un créneau continu dont la fin n\'est pas postérieure au début doit être refusé.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPunctualSlotIsLimitedToOneDay(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));

        self::assertSame(ValidityKind::Punctual, $slot->kind);
        self::assertNull($slot->endDate);
    }

    public function testPunctualSlotRefusesEqualOrReversedTimes(): void
    {
        foreach ([['09:00', '09:00'], ['22:00', '06:00']] as [$start, $end]) {
            try {
                ValiditySlot::punctual(T::date('2026-06-10'), T::time($start), T::time($end));
                self::fail('Un créneau ponctuel ne franchit pas minuit et exige début < fin.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecurringSlotMayCrossMidnight(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-30'), Weekdays::fromIsoDays([5, 6]), T::time('22:00'), T::time('06:00'));

        self::assertSame(ValidityKind::Recurring, $slot->kind);
    }

    public function testRecurringSlotRules(): void
    {
        $days = Weekdays::fromIsoDays([1]);
        foreach ([
            'heures égales' => fn () => ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-30'), $days, T::time('08:00'), T::time('08:00')),
            'fin avant début' => fn () => ValiditySlot::recurring(T::date('2026-06-30'), T::date('2026-06-01'), $days, T::time('08:00'), T::time('09:00')),
            'période démesurée' => fn () => ValiditySlot::recurring(T::date('2026-01-01'), T::date('2040-01-01'), $days, T::time('08:00'), T::time('09:00')),
        ] as $label => $build) {
            try {
                $build();
                self::fail($label.' doit être refusé.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecurrencePeriodLimitIsExactlyTheDocumentedTechnicalGuard(): void
    {
        // DM-083 : 3 660 jours maximum entre la date de début et la date de fin.
        self::assertSame(3660, ValiditySlot::MAX_RECURRENCE_DAYS);
        $start = T::date('2026-01-01');
        $days = Weekdays::fromMask(127);

        $atLimit = ValiditySlot::recurring($start, $start->addDays(3660), $days, T::time('08:00'), T::time('09:00'));
        self::assertSame(ValidityKind::Recurring, $atLimit->kind);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('3660');
        ValiditySlot::recurring($start, $start->addDays(3661), $days, T::time('08:00'), T::time('09:00'));
    }

    public function testRecurringSlotWithSingleDayPeriodIsAllowed(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-06-05'), T::date('2026-06-05'), Weekdays::fromIsoDays([5]), T::time('22:00'), T::time('06:00'));

        self::assertSame(ValidityKind::Recurring, $slot->kind);
    }

    public function testWeekdaysMask(): void
    {
        $days = Weekdays::fromIsoDays([1, 3, 7]);

        self::assertSame(0b1000101, $days->mask);
        self::assertTrue($days->includes(1));
        self::assertFalse($days->includes(2));
        self::assertTrue($days->includes(7));
        self::assertSame(127, Weekdays::fromMask(127)->mask);
    }

    public function testWeekdaysRefuseEmptyOrOutOfRange(): void
    {
        foreach ([fn () => Weekdays::fromMask(0), fn () => Weekdays::fromMask(128), fn () => Weekdays::fromIsoDays([]), fn () => Weekdays::fromIsoDays([0]), fn () => Weekdays::fromIsoDays([8])] as $build) {
            try {
                $build();
                self::fail('Sélection de jours invalide.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLogicalDeletionHasNoWayBack(): void
    {
        $slot = ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('17:00'));
        $deleted = $slot->markDeleted(T::utc('2026-06-01 10:00'));

        self::assertFalse($slot->isDeleted(), 'immuable');
        self::assertTrue($deleted->isDeleted());
        self::assertEquals(T::utc('2026-06-01 10:00'), $deleted->deletedAt);

        $this->expectException(DomainRuleViolation::class);
        $deleted->markDeleted(T::utc('2026-06-02 10:00'));
    }

    public function testDstChoicesRoundTripAsJson(): void
    {
        $choices = new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::End, Occurrence::Second),
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::First),
        ]);

        $data = $choices->toArray();
        self::assertSame([
            ['date' => '2026-10-25', 'field' => 'end', 'occurrence' => 'second'],
            ['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'first'],
        ], $data);
        self::assertEquals($choices, DstChoices::fromArray($data));
        self::assertSame(Occurrence::Second, $choices->find(T::date('2026-10-25'), ChoiceField::End));
        self::assertNull($choices->find(T::date('2026-10-26'), ChoiceField::End));
        self::assertTrue((new DstChoices())->isEmpty());
    }

    public function testDstChoicesRefuseDuplicatesAndMalformedData(): void
    {
        foreach ([
            fn () => new DstChoices([new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::First), new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::Second)]),
            fn () => DstChoices::fromArray([['date' => '2026-10-25', 'field' => 'middle', 'occurrence' => 'first']]),
            fn () => DstChoices::fromArray([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'third']]),
            fn () => DstChoices::fromArray([['field' => 'start', 'occurrence' => 'first']]),
            fn () => DstChoices::fromArray([['date' => 'demain', 'field' => 'start', 'occurrence' => 'first']]),
        ] as $build) {
            try {
                $build();
                self::fail('Choix invalides.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }
}
