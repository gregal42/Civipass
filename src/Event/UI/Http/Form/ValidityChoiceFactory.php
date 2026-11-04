<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\ProblemType;
use App\Event\Domain\Validity\SlotProblem;

/**
 * Pose, pour chaque horaire AMBIGU refusé par le moteur (heure répétée au retour à l'heure d'hiver), la question du choix de
 * l'occurrence (DM-055) : deux options décrites par leur décalage UTC dans le fuseau de l'événement. Rien n'est jamais choisi
 * automatiquement. Les horaires inexistants et les autres refus du moteur deviennent des messages, jamais des choix.
 */
final readonly class ValidityChoiceFactory
{
    private const MAX_MESSAGES = 5;

    public function __construct(private LocalTimeResolver $resolver = new LocalTimeResolver())
    {
    }

    /**
     * @param list<SlotProblem>     $problems
     * @param array<string, string> $posted   choix postés (clé « AAAA-MM-JJ|start|end » => first | second), pour les conserver
     *
     * @return list<array{date: string, field: string, time: string, label: string, options: list<array{value: string, label: string}>, selected: string}>
     */
    public function choices(array $problems, ValiditySlotInput $input, EventTimezone $timezone, array $posted = []): array
    {
        $choices = [];
        foreach ($problems as $problem) {
            if (ProblemType::MissingChoice !== $problem->type || null === $problem->field) {
                continue;
            }
            $time = ChoiceField::Start === $problem->field ? $input->startTime : $input->endTime;
            $candidates = $this->resolver->candidates($problem->date, LocalTime::fromString($time), $timezone);
            if (2 !== \count($candidates)) {
                continue;
            }
            $zone = $timezone->toDateTimeZone();
            $labels = array_map(static fn (\DateTimeImmutable $instant): string => 'UTC'.$instant->setTimezone($zone)->format('P'), $candidates);
            $summer = $candidates[0]->setTimezone($zone)->format('I') !== $candidates[1]->setTimezone($zone)->format('I');
            $key = $problem->date->toString().'|'.$problem->field->value;
            $choices[] = [
                'date' => $problem->date->toString(),
                'field' => $problem->field->value,
                'time' => $time,
                'label' => sprintf('%s du %s : %s se produit deux fois', ChoiceField::Start === $problem->field ? 'Début' : 'Fin', self::french($problem->date), $time),
                'options' => [
                    ['value' => 'first', 'label' => 'Première occurrence — '.$labels[0].($summer ? ' (heure d’été)' : '')],
                    ['value' => 'second', 'label' => 'Deuxième occurrence — '.$labels[1].($summer ? ' (heure d’hiver)' : '')],
                ],
                'selected' => $posted[$key] ?? '',
            ];
        }

        return $choices;
    }

    /**
     * Messages à afficher (horaire inexistant, durée non positive, choix obsolète) : ceux du moteur, bornés.
     *
     * @param list<SlotProblem> $problems
     *
     * @return list<string>
     */
    public function messages(array $problems): array
    {
        $messages = [];
        foreach ($problems as $problem) {
            if (ProblemType::MissingChoice !== $problem->type) {
                $messages[] = $problem->message;
            }
        }
        $extra = \count($messages) - self::MAX_MESSAGES;

        return $extra > 0 ? [...\array_slice($messages, 0, self::MAX_MESSAGES), sprintf('… et %d autre(s) date(s) concernée(s).', $extra)] : $messages;
    }

    private static function french(LocalDate $date): string
    {
        [$y, $m, $d] = explode('-', $date->toString());

        return sprintf('%s/%s/%s', $d, $m, $y);
    }
}
