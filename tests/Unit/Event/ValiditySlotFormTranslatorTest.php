<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\Read\SlotView;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\UI\Http\Form\ValiditySlotFormTranslator;
use PHPUnit\Framework\TestCase;

final class ValiditySlotFormTranslatorTest extends TestCase
{
    private ValiditySlotFormTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ValiditySlotFormTranslator();
    }

    public function testAContinuousSlotIsTranslatedInLocalTime(): void
    {
        [$input, $errors] = $this->translator->fromPost('new', ['new' => ['mode' => 'continuous'], 'new_c' => ['start_date' => '2026-12-01', 'start_time' => '08:00', 'end_date' => '2026-12-02', 'end_time' => '18:00']]);

        self::assertSame([], $errors);
        self::assertNotNull($input);
        self::assertSame('CONTINUOUS', $input->kind);
        self::assertSame(['2026-12-01', '08:00', '2026-12-02', '18:00'], [$input->startDate, $input->startTime, $input->endDate, $input->endTime]);
        self::assertSame([], $input->isoWeekdays);
    }

    public function testAPunctualSlotUsesOneDate(): void
    {
        [$input, $errors] = $this->translator->fromPost('new', ['new' => ['mode' => 'single', 'date' => '2026-12-05', 'from_time' => '09:00', 'to_time' => '11:00']]);

        self::assertSame([], $errors);
        self::assertSame('PUNCTUAL', $input->kind);
        self::assertNull($input->endDate);
    }

    public function testEveryDayMeansAllTheSevenIsoDaysAndSomeDaysAreSortedAndDeduplicated(): void
    {
        $base = ['mode' => 'recurring', 'rec_from_time' => '07:00', 'rec_to_time' => '19:00', 'rec_from' => '2026-12-07', 'rec_to' => '2026-12-18'];

        [$every] = $this->translator->fromPost('new', ['new' => $base + ['every' => 'day']]);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $every?->isoWeekdays);

        [$some] = $this->translator->fromPost('new', ['new' => $base + ['every' => 'days', 'days' => ['5', '1', '5', '3', '9', 'x']]]);
        self::assertSame([1, 3, 5], $some?->isoWeekdays);
    }

    public function testAnOvernightRecurrenceIsLeftToTheEngine(): void
    {
        [$input, $errors] = $this->translator->fromPost('new', ['new' => ['mode' => 'recurring', 'every' => 'day', 'rec_from_time' => '22:00', 'rec_to_time' => '06:00', 'rec_from' => '2026-12-07', 'rec_to' => '2026-12-18']]);

        self::assertSame([], $errors);
        self::assertSame('06:00', $input?->endTime);
    }

    public function testShapeErrorsAreReportedByComponentField(): void
    {
        self::assertSame(['mode'], array_keys($this->translator->fromPost('new', [])[1]));
        self::assertSame(['mode'], array_keys($this->translator->fromPost('new', ['new' => ['mode' => 'weekly']])[1]));
        self::assertSame(['end'], array_keys($this->translator->fromPost('new', ['new' => ['mode' => 'continuous'], 'new_c' => ['start_date' => '2026-12-01']])[1]));
        self::assertSame(['date', 'times'], array_keys($this->translator->fromPost('new', ['new' => ['mode' => 'single']])[1]));
        self::assertSame(['days', 'times', 'rec_range'], array_keys($this->translator->fromPost('new', ['new' => ['mode' => 'recurring', 'every' => 'days']])[1]));
    }

    public function testImpossibleDatesAndTimesAreRefused(): void
    {
        foreach (['2026-02-30', '2026-13-01', '01/12/2026', '2026-1-1'] as $date) {
            [$input, $errors] = $this->translator->fromPost('new', ['new' => ['mode' => 'single', 'date' => $date, 'from_time' => '09:00', 'to_time' => '10:00']]);
            self::assertNull($input, $date);
            self::assertArrayHasKey('date', $errors, $date);
        }
        foreach (['24:00', '9:00', '09:60', '09h00', ''] as $time) {
            [$input, $errors] = $this->translator->fromPost('new', ['new' => ['mode' => 'single', 'date' => '2026-12-05', 'from_time' => $time, 'to_time' => '10:00']]);
            self::assertNull($input, $time);
            self::assertArrayHasKey('times', $errors, $time);
        }
    }

    public function testTheChosenOccurrencesAreCarriedAndInvalidOnesIgnored(): void
    {
        [$input] = $this->translator->fromPost('new', ['new' => ['mode' => 'single', 'date' => '2026-10-25', 'from_time' => '02:30', 'to_time' => '04:00', 'dst' => [
            '2026-10-25' => ['start' => 'second', 'middle' => 'first'],
            'pas-une-date' => ['start' => 'first'],
            '2026-10-26' => ['end' => 'third'],
        ]]]);

        self::assertSame([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']], $input?->dstChoices);
    }

    public function testNothingOutsideTheFormFieldsIsReadAndArraysAreNotTrusted(): void
    {
        [$input, $errors] = $this->translator->fromPost('new', ['new' => 'texte', 'new_c' => 'texte']);

        self::assertNull($input);
        self::assertArrayHasKey('mode', $errors);
    }

    public function testASavedSlotRoundTripsToItsFormValuesAndToTheEngineInput(): void
    {
        $recurring = new SlotView('id-1', ValidityKind::Recurring, '2026-12-07', '22:00', '2026-12-18', '06:00', [1, 3], [['date' => '2026-12-08', 'field' => 'start', 'occurrence' => 'first']]);

        $values = $this->translator->valuesOf($recurring);
        self::assertSame('recurring', $values['mode']);
        self::assertSame('days', $values['every']);
        self::assertSame([1, 3], $values['days']);
        self::assertSame(['22:00', '06:00', '2026-12-07', '2026-12-18'], [$values['from_time'], $values['to_time'], $values['rec_from'], $values['rec_to']]);

        $input = $this->translator->inputOf($recurring);
        self::assertSame('RECURRING', $input->kind);
        self::assertSame($recurring->dstChoices, $input->dstChoices, 'choix d’occurrence conservés au remplacement');
        self::assertSame([1, 3], $input->isoWeekdays);

        $everyDay = new SlotView('id-2', ValidityKind::Recurring, '2026-12-07', '07:00', '2026-12-18', '19:00', [1, 2, 3, 4, 5, 6, 7]);
        self::assertSame('day', $this->translator->valuesOf($everyDay)['every']);

        $punctual = new SlotView('id-3', ValidityKind::Punctual, '2026-12-05', '09:00', null, '11:00');
        self::assertSame(['mode' => 'single', 'date' => '2026-12-05', 'from_time' => '09:00', 'to_time' => '11:00'], $this->translator->valuesOf($punctual));
    }
}
