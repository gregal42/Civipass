<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Event\Domain\Time\Occurrence;

/**
 * Vérifie un créneau dans le fuseau de l'événement (DM-055, DM-063, DM-064, DT-020) :
 * - horaire inexistant : refus, avec la date et l'horaire en cause (récurrences comprises) ;
 * - horaire ambigu : un choix explicite est exigé pour chaque (date, borne) ;
 * - choix obsolète : un choix qui ne correspond à aucun horaire ambigu est refusé ;
 * - durée résolue : la fin résolue doit être strictement postérieure au début résolu.
 * Aucun ajustement automatique, aucune occurrence supprimée silencieusement.
 */
final readonly class SlotInspector
{
    public function __construct(
        private LocalTimeResolver $resolver = new LocalTimeResolver(),
        private SlotOccurrenceGenerator $generator = new SlotOccurrenceGenerator(),
    ) {
    }

    public function inspect(ValiditySlot $slot, EventTimezone $timezone): SlotInspection
    {
        $problems = [];
        $required = [];
        $requiredKeys = [];

        foreach ($this->generator->generate($slot) as $occurrence) {
            $start = $this->resolvePoint($slot, $timezone, $occurrence->startDate, ChoiceField::Start, $occurrence->startTime, $problems, $required, $requiredKeys);
            $end = $this->resolvePoint($slot, $timezone, $occurrence->endDate, ChoiceField::End, $occurrence->endTime, $problems, $required, $requiredKeys);
            if (null !== $start && null !== $end && $end <= $start) {
                $problems[] = new SlotProblem(
                    ProblemType::NonPositiveDuration,
                    $occurrence->startDate,
                    null,
                    sprintf('Avec les occurrences choisies, la fin du créneau du %s n\'est pas postérieure à son début.', $occurrence->startDate),
                );
            }
        }

        foreach ($slot->dstChoices->all() as $choice) {
            if (!isset($requiredKeys[$choice->key()])) {
                $problems[] = new SlotProblem(
                    ProblemType::ObsoleteChoice,
                    $choice->date,
                    $choice->field,
                    sprintf('Le choix d\'occurrence enregistré pour le %s ne correspond à aucun horaire ambigu.', $choice->date),
                );
            }
        }

        return new SlotInspection($problems, $required);
    }

    /**
     * @throws InvalidValiditySlot
     */
    public function assertValid(ValiditySlot $slot, EventTimezone $timezone): void
    {
        $inspection = $this->inspect($slot, $timezone);
        if (!$inspection->isValid()) {
            throw new InvalidValiditySlot($inspection->problems);
        }
    }

    /**
     * Retire les choix devenus invalides après une modification ; les nouveaux choix nécessaires ne sont jamais
     * générés : ils restent à demander explicitement à l'utilisateur (DT-020).
     */
    public function withoutObsoleteChoices(ValiditySlot $slot, EventTimezone $timezone): ValiditySlot
    {
        $keys = [];
        foreach ($this->inspect($slot, $timezone)->requiredChoices as $point) {
            $keys[$point->date->toString().'|'.$point->field->value] = true;
        }

        return $slot->withDstChoices(new DstChoices(array_values(array_filter(
            $slot->dstChoices->all(),
            static fn (DstChoice $choice): bool => isset($keys[$choice->key()]),
        ))));
    }

    /**
     * @param list<SlotProblem>   $problems
     * @param list<SlotPoint>     $required
     * @param array<string, true> $requiredKeys
     */
    private function resolvePoint(ValiditySlot $slot, EventTimezone $timezone, LocalDate $date, ChoiceField $field, LocalTime $time, array &$problems, array &$required, array &$requiredKeys): ?\DateTimeImmutable
    {
        $candidates = $this->resolver->candidates($date, $time, $timezone);

        if ([] === $candidates) {
            $problems[] = new SlotProblem(
                ProblemType::NonexistentTime,
                $date,
                $field,
                sprintf('L\'horaire %s du %s n\'existe pas (changement d\'heure, fuseau %s) : choisissez un horaire valide.', $time, $date, $timezone),
            );

            return null;
        }
        if (1 === \count($candidates)) {
            return $candidates[0];
        }

        $required[] = new SlotPoint($date, $field, $time);
        $requiredKeys[$date->toString().'|'.$field->value] = true;
        $choice = $slot->dstChoices->find($date, $field);
        if (null === $choice) {
            $problems[] = new SlotProblem(
                ProblemType::MissingChoice,
                $date,
                $field,
                sprintf('L\'horaire %s du %s se produit deux fois (changement d\'heure, fuseau %s) : choisissez la première ou la deuxième occurrence.', $time, $date, $timezone),
            );

            return null;
        }

        return Occurrence::First === $choice ? $candidates[0] : $candidates[1];
    }
}
