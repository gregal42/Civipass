<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Validity;

use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\DstChoice;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ProblemType;
use App\Event\Domain\Validity\SlotInspection;
use App\Event\Domain\Validity\SlotInspector;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\TestCase;

final class SlotInspectorTest extends TestCase
{
    private SlotInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new SlotInspector();
    }

    /**
     * @return list<string> « type date champ »
     */
    private static function problems(SlotInspection $inspection): array
    {
        return array_map(static fn ($p): string => sprintf('%s %s %s', $p->type->value, $p->date, $p->field->value ?? '-'), $inspection->problems);
    }

    private static function sundays(string $from, string $to, string $start = '02:30', string $end = '04:00', ?DstChoices $choices = null): ValiditySlot
    {
        return ValiditySlot::recurring(T::date($from), T::date($to), Weekdays::fromIsoDays([7]), T::time($start), T::time($end), $choices ?? new DstChoices());
    }

    public function testRecurrenceWithANonexistentTimeIsRefusedAndNamesTheDate(): void
    {
        // 29 mars 2026 (dimanche) : 02:30 n'existe pas. Les autres dimanches de la période sont valides.
        $slot = self::sundays('2026-03-22', '2026-04-05');

        $inspection = $this->inspector->inspect($slot, T::paris());

        self::assertSame(['NONEXISTENT_TIME 2026-03-29 start'], self::problems($inspection));
        try {
            $this->inspector->assertValid($slot, T::paris());
            self::fail('La récurrence doit être refusée.');
        } catch (InvalidValiditySlot $e) {
            self::assertStringContainsString('02:30 du 2026-03-29 n\'existe pas', $e->getMessage());
            self::assertCount(1, $e->problems);
        }
    }

    public function testRecurrenceNotReachingTheTransitionDateIsValid(): void
    {
        self::assertTrue($this->inspector->inspect(self::sundays('2026-04-05', '2026-04-19'), T::paris())->isValid());

        // Le jour de la transition n'est pas un jour sélectionné : aucune occurrence concernée.
        $monToSat = ValiditySlot::recurring(T::date('2026-03-22'), T::date('2026-04-05'), Weekdays::fromIsoDays([1, 2, 3, 4, 5, 6]), T::time('02:30'), T::time('04:00'));
        self::assertTrue($this->inspector->inspect($monToSat, T::paris())->isValid());
    }

    public function testOvernightRecurrenceEndingInTheGapIsRefused(): void
    {
        // Samedi 28 mars 22:00 → dimanche 29 mars 02:30 (inexistant).
        $slot = ValiditySlot::recurring(T::date('2026-03-28'), T::date('2026-03-28'), Weekdays::fromIsoDays([6]), T::time('22:00'), T::time('02:30'));

        self::assertSame(['NONEXISTENT_TIME 2026-03-29 end'], self::problems($this->inspector->inspect($slot, T::paris())));
    }

    public function testNonexistentTimeIsRefusedForContinuousAndPunctualSlots(): void
    {
        $continuous = ValiditySlot::continuous(T::date('2026-03-29'), T::time('01:30'), T::date('2026-03-29'), T::time('02:30'));
        $punctual = ValiditySlot::punctual(T::date('2026-03-29'), T::time('02:15'), T::time('05:00'));

        self::assertSame(['NONEXISTENT_TIME 2026-03-29 end'], self::problems($this->inspector->inspect($continuous, T::paris())));
        self::assertSame(['NONEXISTENT_TIME 2026-03-29 start'], self::problems($this->inspector->inspect($punctual, T::paris())));
    }

    public function testAmbiguousTimeRequiresAnExplicitChoiceForTheDateConcerned(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-10-24'), T::date('2026-10-26'), Weekdays::fromMask(127), T::time('02:30'), T::time('04:00'));

        $inspection = $this->inspector->inspect($slot, T::paris());

        self::assertSame(['MISSING_CHOICE 2026-10-25 start'], self::problems($inspection));
        self::assertCount(1, $inspection->requiredChoices);
        self::assertSame('2026-10-25', $inspection->requiredChoices[0]->date->toString());
        self::assertSame(ChoiceField::Start, $inspection->requiredChoices[0]->field);
        self::assertSame('02:30', $inspection->requiredChoices[0]->time->toString());
    }

    public function testAnExplicitChoiceMakesTheSlotValid(): void
    {
        foreach ([Occurrence::First, Occurrence::Second] as $occurrence) {
            $slot = ValiditySlot::recurring(T::date('2026-10-24'), T::date('2026-10-26'), Weekdays::fromMask(127), T::time('02:30'), T::time('04:00'), new DstChoices([
                new DstChoice(T::date('2026-10-25'), ChoiceField::Start, $occurrence),
            ]));

            self::assertTrue($this->inspector->inspect($slot, T::paris())->isValid());
        }
    }

    public function testOvernightRecurrenceEndingInTheRepeatedHourNeedsAChoiceOnTheEndDate(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-10-24'), T::date('2026-10-24'), Weekdays::fromIsoDays([6]), T::time('22:00'), T::time('02:30'));

        self::assertSame(['MISSING_CHOICE 2026-10-25 end'], self::problems($this->inspector->inspect($slot, T::paris())));

        $chosen = $slot->withDstChoices(new DstChoices([new DstChoice(T::date('2026-10-25'), ChoiceField::End, Occurrence::Second)]));
        self::assertTrue($this->inspector->inspect($chosen, T::paris())->isValid());
    }

    public function testChoicesNotMatchingAnAmbiguousTimeAreRefused(): void
    {
        $slot = ValiditySlot::recurring(T::date('2026-10-24'), T::date('2026-10-26'), Weekdays::fromMask(127), T::time('02:30'), T::time('04:00'), new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::First),
            new DstChoice(T::date('2026-10-24'), ChoiceField::Start, Occurrence::First),
            new DstChoice(T::date('2026-10-25'), ChoiceField::End, Occurrence::First),
        ]));

        self::assertSame(
            ['OBSOLETE_CHOICE 2026-10-24 start', 'OBSOLETE_CHOICE 2026-10-25 end'],
            self::problems($this->inspector->inspect($slot, T::paris())),
        );
    }

    public function testResolvedEndMustBeAfterResolvedStart(): void
    {
        $times = [T::time('02:15'), T::time('02:45')];
        $slot = static fn (Occurrence $start, Occurrence $end): ValiditySlot => ValiditySlot::punctual(T::date('2026-10-25'), $times[0], $times[1], new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, $start),
            new DstChoice(T::date('2026-10-25'), ChoiceField::End, $end),
        ]));

        self::assertSame(
            ['NON_POSITIVE_DURATION 2026-10-25 -'],
            self::problems($this->inspector->inspect($slot(Occurrence::Second, Occurrence::First), T::paris())),
        );
        foreach ([[Occurrence::First, Occurrence::First], [Occurrence::Second, Occurrence::Second], [Occurrence::First, Occurrence::Second]] as [$start, $end]) {
            self::assertTrue($this->inspector->inspect($slot($start, $end), T::paris())->isValid());
        }
    }

    public function testSaintPierreEtMiquelonRulesApplyToItsOwnTransitions(): void
    {
        $zone = T::zone('America/Miquelon');
        $gap = ValiditySlot::punctual(T::date('2026-03-08'), T::time('02:30'), T::time('04:00'));
        $repeat = ValiditySlot::punctual(T::date('2026-11-01'), T::time('01:30'), T::time('03:00'));

        self::assertSame(['NONEXISTENT_TIME 2026-03-08 start'], self::problems($this->inspector->inspect($gap, $zone)));
        self::assertSame(['MISSING_CHOICE 2026-11-01 start'], self::problems($this->inspector->inspect($repeat, $zone)));
        // La même date n'a rien de particulier à La Réunion.
        self::assertTrue($this->inspector->inspect($gap, T::zone('Indian/Reunion'))->isValid());
    }

    public function testZonesWithoutDaylightSavingNeverNeedAChoice(): void
    {
        foreach (['Indian/Reunion', 'Pacific/Tahiti', 'Pacific/Marquesas', 'Pacific/Gambier', 'Pacific/Noumea', 'America/Guadeloupe'] as $zone) {
            $daily = ValiditySlot::recurring(T::date('2026-01-01'), T::date('2026-12-31'), Weekdays::fromMask(127), T::time('02:30'), T::time('04:00'));
            $inspection = $this->inspector->inspect($daily, T::zone($zone));

            self::assertTrue($inspection->isValid(), $zone);
            self::assertSame([], $inspection->requiredChoices, $zone);
        }
        $withChoice = ValiditySlot::punctual(T::date('2026-10-25'), T::time('02:30'), T::time('04:00'), new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::First),
        ]));
        self::assertSame(['OBSOLETE_CHOICE 2026-10-25 start'], self::problems($this->inspector->inspect($withChoice, T::zone('Indian/Reunion'))));
    }

    public function testObsoleteChoicesAreDroppedAndNewOnesAreNeverInvented(): void
    {
        $slot = self::sundays('2026-10-18', '2026-10-25', choices: new DstChoices([
            new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::Second),
            new DstChoice(T::date('2026-10-18'), ChoiceField::Start, Occurrence::First),
        ]));

        $pruned = $this->inspector->withoutObsoleteChoices($slot, T::paris());

        self::assertSame([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']], $pruned->dstChoices->toArray());
        self::assertTrue($this->inspector->inspect($pruned, T::paris())->isValid());

        // Heure de début changée : plus aucun horaire ambigu, tous les choix tombent.
        $moved = ValiditySlot::recurring(T::date('2026-10-18'), T::date('2026-10-25'), Weekdays::fromIsoDays([7]), T::time('03:30'), T::time('04:00'), $slot->dstChoices);
        $movedPruned = $this->inspector->withoutObsoleteChoices($moved, T::paris());
        self::assertTrue($movedPruned->dstChoices->isEmpty());
        self::assertTrue($this->inspector->inspect($movedPruned, T::paris())->isValid());

        // Période étendue : le nouveau choix nécessaire reste à demander.
        $extended = self::sundays('2026-10-18', '2026-10-25', choices: new DstChoices());
        $stillEmpty = $this->inspector->withoutObsoleteChoices($extended, T::paris());
        self::assertTrue($stillEmpty->dstChoices->isEmpty());
        self::assertSame(['MISSING_CHOICE 2026-10-25 start'], self::problems($this->inspector->inspect($stillEmpty, T::paris())));
    }

    public function testProblemTypesAreExposed(): void
    {
        $inspection = $this->inspector->inspect(self::sundays('2026-03-22', '2026-04-05'), T::paris());

        self::assertSame(ProblemType::NonexistentTime, $inspection->problems[0]->type);
    }
}
