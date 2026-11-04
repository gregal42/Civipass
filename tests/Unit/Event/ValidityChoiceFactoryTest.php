<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\ProblemType;
use App\Event\Domain\Validity\SlotProblem;
use App\Event\UI\Http\Form\ValidityChoiceFactory;
use PHPUnit\Framework\TestCase;

final class ValidityChoiceFactoryTest extends TestCase
{
    private function problem(ProblemType $type, string $date, ?ChoiceField $field, string $message = 'm'): SlotProblem
    {
        return new SlotProblem($type, LocalDate::fromString($date), $field, $message);
    }

    public function testARepeatedHourBecomesAQuestionWithBothOffsetsAndNoDefault(): void
    {
        $input = new ValiditySlotInput('PUNCTUAL', '2026-10-25', '02:30', '04:00');

        $choices = (new ValidityChoiceFactory())->choices([$this->problem(ProblemType::MissingChoice, '2026-10-25', ChoiceField::Start)], $input, EventTimezone::fromIdentifier('Europe/Paris'));

        self::assertCount(1, $choices);
        self::assertSame(['2026-10-25', 'start', '02:30'], [$choices[0]['date'], $choices[0]['field'], $choices[0]['time']]);
        self::assertSame('', $choices[0]['selected'], 'rien n’est choisi à la place de l’utilisateur');
        self::assertSame(['first', 'second'], array_column($choices[0]['options'], 'value'));
        self::assertStringContainsString('UTC+02:00 (heure d’été)', $choices[0]['options'][0]['label']);
        self::assertStringContainsString('UTC+01:00 (heure d’hiver)', $choices[0]['options'][1]['label']);
    }

    public function testTheEndPointUsesTheEndTimeAndAPostedChoiceIsKept(): void
    {
        $input = new ValiditySlotInput('CONTINUOUS', '2026-10-24', '20:00', '02:45', '2026-10-25');

        $choices = (new ValidityChoiceFactory())->choices(
            [$this->problem(ProblemType::MissingChoice, '2026-10-25', ChoiceField::End)],
            $input,
            EventTimezone::fromIdentifier('Europe/Paris'),
            ['2026-10-25|end' => 'first'],
        );

        self::assertSame('02:45', $choices[0]['time']);
        self::assertSame('first', $choices[0]['selected']);
    }

    public function testOnlyMissingChoicesBecomeQuestionsAndANonAmbiguousTimeNeverDoes(): void
    {
        $input = new ValiditySlotInput('PUNCTUAL', '2026-12-05', '09:00', '10:00');
        $problems = [
            $this->problem(ProblemType::NonexistentTime, '2027-03-28', ChoiceField::Start),
            $this->problem(ProblemType::ObsoleteChoice, '2026-12-05', ChoiceField::Start),
            $this->problem(ProblemType::MissingChoice, '2026-12-05', ChoiceField::Start), // pas ambigu dans le fuseau : aucune question
        ];

        self::assertSame([], (new ValidityChoiceFactory())->choices($problems, $input, EventTimezone::fromIdentifier('Europe/Paris')));
    }

    public function testAZoneWithoutClockChangeDoesNotLabelSummerOrWinter(): void
    {
        $input = new ValiditySlotInput('PUNCTUAL', '2026-12-05', '09:00', '10:00');

        self::assertSame([], (new ValidityChoiceFactory())->choices([$this->problem(ProblemType::MissingChoice, '2026-12-05', ChoiceField::Start)], $input, EventTimezone::fromIdentifier('Pacific/Tahiti')));
    }

    public function testTheMessagesAreTheEngineOnesWithoutTheChoicesAndBounded(): void
    {
        $factory = new ValidityChoiceFactory();
        $problems = [$this->problem(ProblemType::MissingChoice, '2026-10-25', ChoiceField::Start, 'à choisir')];
        for ($i = 1; $i <= 8; ++$i) {
            $problems[] = $this->problem(ProblemType::NonexistentTime, '2027-03-28', ChoiceField::Start, 'message '.$i);
        }

        $messages = $factory->messages($problems);

        self::assertCount(6, $messages);
        self::assertSame('message 1', $messages[0]);
        self::assertStringContainsString('3 autre(s)', $messages[5]);
        self::assertNotContains('à choisir', $messages);
    }
}
