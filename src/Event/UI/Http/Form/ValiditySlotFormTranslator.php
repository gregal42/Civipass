<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Read\SlotView;
use App\Event\Domain\Validity\ValidityKind;

/**
 * Traduit les saisies du formulaire de créneau (composant `validity_form`) en ValiditySlotInput, en heures LOCALES du fuseau de
 * l'événement. Il ne contrôle que la FORME (type connu, dates AAAA-MM-JJ, heures HH:MM, champs présents, jours de 1 à 7) ;
 * l'ordre début/fin, les horaires inexistants ou ambigus, la limite de période et le plafond de créneaux restent dans le moteur
 * (ValiditySlot, SlotInspector, ZoneValidityConfigurator). Aucun ajustement automatique d'horaire.
 *
 * Champs postés pour un formulaire d'identifiant `{id}` : `{id}[mode]` (continuous | single | recurring), plage continue dans
 * `{id}_c[start_date|start_time|end_date|end_time]`, ponctuel `{id}[date|from_time|to_time]`, récurrence
 * `{id}[every|days[]|rec_from_time|rec_to_time|rec_from|rec_to]`, choix d'occurrence `{id}[dst][AAAA-MM-JJ][start|end]` (first | second).
 */
final readonly class ValiditySlotFormTranslator
{
    private const MODES = ['continuous' => ValidityKind::Continuous, 'single' => ValidityKind::Punctual, 'recurring' => ValidityKind::Recurring];

    /**
     * @param array<string, mixed> $post POST complet
     *
     * @return array{ValiditySlotInput|null, array<string, string>, array<string, mixed>} commande, erreurs par champ du composant, valeurs à réafficher
     */
    public function fromPost(string $id, array $post): array
    {
        $fields = $this->array($post[$id] ?? null);
        $continuous = $this->array($post[$id.'_c'] ?? null);
        $mode = $this->text($fields, 'mode');
        $values = $this->values($mode, $fields, $continuous);

        $errors = [];
        $kind = self::MODES[$mode] ?? null;
        if (null === $kind) {
            return [null, ['mode' => 'Choisissez le type de créneau.'], $values];
        }

        $input = match ($kind) {
            ValidityKind::Continuous => $this->continuous($continuous, $fields, $errors),
            ValidityKind::Punctual => $this->punctual($fields, $errors),
            ValidityKind::Recurring => $this->recurring($fields, $errors),
        };

        return [[] === $errors ? $input : null, $errors, $values];
    }

    /**
     * Valeurs d'un créneau enregistré, pour préremplir son formulaire de modification.
     *
     * @return array<string, mixed>
     */
    public function valuesOf(SlotView $slot): array
    {
        return match ($slot->kind) {
            ValidityKind::Continuous => ['mode' => 'continuous', 'start_date' => $slot->startDate, 'start_time' => $slot->startTime, 'end_date' => $slot->endDate ?? '', 'end_time' => $slot->endTime],
            ValidityKind::Punctual => ['mode' => 'single', 'date' => $slot->startDate, 'from_time' => $slot->startTime, 'to_time' => $slot->endTime],
            ValidityKind::Recurring => ['mode' => 'recurring', 'every' => 7 === \count($slot->isoWeekdays) ? 'day' : 'days', 'days' => $slot->isoWeekdays, 'from_time' => $slot->startTime, 'to_time' => $slot->endTime, 'rec_from' => $slot->startDate, 'rec_to' => $slot->endDate ?? ''],
        };
    }

    /** Créneau enregistré, rendu tel quel au moteur lors du remplacement de la configuration (choix d'occurrence conservés). */
    public function inputOf(SlotView $slot): ValiditySlotInput
    {
        return new ValiditySlotInput($slot->kind->value, $slot->startDate, $slot->startTime, $slot->endTime, $slot->endDate, $slot->isoWeekdays, $slot->dstChoices);
    }

    /**
     * @param array<string, mixed>  $continuous
     * @param array<string, mixed>  $fields
     * @param array<string, string> $errors
     */
    private function continuous(array $continuous, array $fields, array &$errors): ?ValiditySlotInput
    {
        $startDate = $this->text($continuous, 'start_date');
        $startTime = $this->text($continuous, 'start_time');
        $endDate = $this->text($continuous, 'end_date');
        $endTime = $this->text($continuous, 'end_time');
        if (!$this->isDate($startDate) || !$this->isTime($startTime) || !$this->isDate($endDate) || !$this->isTime($endTime)) {
            $errors['end'] = 'Renseignez la date et l’heure de début, puis la date et l’heure de fin.';

            return null;
        }

        return new ValiditySlotInput(ValidityKind::Continuous->value, $startDate, $startTime, $endTime, $endDate, [], $this->choices($fields));
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $errors
     */
    private function punctual(array $fields, array &$errors): ?ValiditySlotInput
    {
        $date = $this->text($fields, 'date');
        $from = $this->text($fields, 'from_time');
        $to = $this->text($fields, 'to_time');
        if (!$this->isDate($date)) {
            $errors['date'] = 'Indiquez la date de l’accès.';
        }
        if (!$this->isTime($from) || !$this->isTime($to)) {
            $errors['times'] = 'Renseignez l’heure de début et l’heure de fin.';
        }
        if ([] !== $errors) {
            return null;
        }

        return new ValiditySlotInput(ValidityKind::Punctual->value, $date, $from, $to, null, [], $this->choices($fields));
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $errors
     */
    private function recurring(array $fields, array &$errors): ?ValiditySlotInput
    {
        $from = $this->text($fields, 'rec_from_time');
        $to = $this->text($fields, 'rec_to_time');
        $start = $this->text($fields, 'rec_from');
        $end = $this->text($fields, 'rec_to');
        $days = [1, 2, 3, 4, 5, 6, 7];
        if ('days' === $this->text($fields, 'every')) {
            $days = $this->days($fields['days'] ?? null);
            if ([] === $days) {
                $errors['days'] = 'Sélectionnez au moins un jour de la semaine.';
            }
        }
        if (!$this->isTime($from) || !$this->isTime($to)) {
            $errors['times'] = 'Renseignez l’heure de début et l’heure de fin.';
        }
        if (!$this->isDate($start) || !$this->isDate($end)) {
            $errors['rec_range'] = 'Renseignez la date de début et la date de fin de la récurrence.';
        }
        if ([] !== $errors) {
            return null;
        }

        return new ValiditySlotInput(ValidityKind::Recurring->value, $start, $from, $to, $end, $days, $this->choices($fields));
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $continuous
     *
     * @return array<string, mixed>
     */
    private function values(string $mode, array $fields, array $continuous): array
    {
        return [
            'mode' => '' === $mode ? 'continuous' : $mode,
            'start_date' => $this->text($continuous, 'start_date'),
            'start_time' => $this->text($continuous, 'start_time'),
            'end_date' => $this->text($continuous, 'end_date'),
            'end_time' => $this->text($continuous, 'end_time'),
            'date' => $this->text($fields, 'date'),
            'from_time' => '' !== $this->text($fields, 'from_time') ? $this->text($fields, 'from_time') : $this->text($fields, 'rec_from_time'),
            'to_time' => '' !== $this->text($fields, 'to_time') ? $this->text($fields, 'to_time') : $this->text($fields, 'rec_to_time'),
            'every' => '' !== $this->text($fields, 'every') ? $this->text($fields, 'every') : 'day',
            'days' => $this->days($fields['days'] ?? null),
            'rec_from' => $this->text($fields, 'rec_from'),
            'rec_to' => $this->text($fields, 'rec_to'),
        ];
    }

    /**
     * Choix d'occurrence postés, tels quels : le moteur refuse un choix qui ne correspond à aucun horaire ambigu.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<array{date: string, field: string, occurrence: string}>
     */
    public function choices(array $fields): array
    {
        $choices = [];
        foreach ($this->array($fields['dst'] ?? null) as $date => $byField) {
            foreach ($this->array($byField) as $field => $occurrence) {
                if (\is_string($date) && \is_string($field) && \is_string($occurrence) && $this->isDate($date) && \in_array($field, ['start', 'end'], true) && \in_array($occurrence, ['first', 'second'], true)) {
                    $choices[] = ['date' => $date, 'field' => $field, 'occurrence' => $occurrence];
                }
            }
        }

        return $choices;
    }

    /**
     * @return list<int>
     */
    private function days(mixed $value): array
    {
        $days = [];
        foreach ($this->array($value) as $day) {
            if (\is_string($day) && 1 === preg_match('/^[1-7]$/', $day)) {
                $days[(int) $day] = (int) $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private function text(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function isDate(string $value): bool
    {
        return 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function isTime(string $value): bool
    {
        return 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
