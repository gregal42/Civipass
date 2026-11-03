<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\Read\EventDetail;
use App\Event\Domain\Event;
use App\Event\Domain\EventStatus;
use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Time\LocalTimeResolver;
use App\Event\Domain\Time\NonexistentLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Traduit les saisies du formulaire en commandes immuables du moteur métier. Il ne valide que la FORME (champs
 * obligatoires, formats, fuseau connu) et convertit les horaires locaux en instants UTC dans le fuseau de l'événement avec
 * LocalTimeResolver : horaire inexistant refusé, horaire répété signalé avec choix explicite de l'occurrence (DM-055),
 * jamais le comportement implicite de PHP. Les règles métier (fin après début, champs verrouillés après activation, fin dans le
 * passé, type disponible) restent dans le domaine et sont appliquées par les services : un champ verrouillé reçu en POST est
 * transmis tel quel et refusé par le domaine, jamais ignoré en silence.
 */
final readonly class EventFormTranslator
{
    private LocalTimeResolver $resolver;

    public function __construct()
    {
        $this->resolver = new LocalTimeResolver();
    }

    /**
     * @param ?EventTimezone $resolved     fuseau déduit de la commune organisatrice, null s'il ne peut pas l'être
     * @param list<string>   $allowedTypes codes des types proposés
     */
    public function forCreate(EventFormData $data, ?EventTimezone $resolved, array $allowedTypes): FormTranslation
    {
        $errors = [];
        $choices = [];
        $name = $this->name($data, $errors);
        if ('' === $data->type || !\in_array($data->type, $allowedTypes, true)) {
            $errors['type'] = '' === $data->type ? 'Choisissez le type de l’événement.' : 'Ce type d’événement n’est pas proposé.';
        }

        $timezone = $resolved;
        if (null === $resolved) {
            $timezone = $this->timezone($data->timezone, $errors, required: true);
        }
        $startsAt = null;
        if ('' === $data->startDate && '' === $data->startTime) {
            $errors['start_date'] = 'Indiquez la date de début.';
            $errors['start_time'] = 'Indiquez l’heure de début.';
        } elseif (null !== $timezone) {
            $startsAt = $this->instant('start', $data->startDate, $data->startTime, $data->startOccurrence, $timezone, required: true, errors: $errors, choices: $choices);
        }
        $endsAt = null !== $timezone ? $this->instant('end', $data->endDate, $data->endTime, $data->endOccurrence, $timezone, required: false, errors: $errors, choices: $choices) : null;

        if ([] !== $errors || null === $startsAt) {
            return new FormTranslation(null, $errors, $choices);
        }

        return new FormTranslation(new CreateEventCommand(
            $data->type,
            $name,
            $startsAt,
            $endsAt,
            null === $resolved ? $timezone->identifier() : null, // fuseau déduit de la commune : jamais transmis par le client
            $data->description,
            $data->instructions,
        ));
    }

    /**
     * @param list<string> $allowedTypes codes des types proposés (le type courant y figure)
     */
    public function forUpdate(EventFormData $data, EventDetail $current, array $allowedTypes): FormTranslation
    {
        $errors = [];
        $choices = [];
        $name = $this->name($data, $errors);
        if ('' !== $data->type && !\in_array($data->type, $allowedTypes, true)) {
            $errors['type'] = 'Ce type d’événement n’est pas proposé.';
        }
        $currentTimezone = EventTimezone::fromIdentifier($current->timezone);
        $postedTimezone = '' === $data->timezone ? null : $this->timezone($data->timezone, $errors, required: false);
        // Les horaires se saisissent dans le fuseau de l'événement ; un changement de fuseau n'est possible qu'en brouillon.
        $timezone = EventStatus::Draft === $current->status && null !== $postedTimezone ? $postedTimezone : $currentTimezone;

        $startsAt = null;
        if ('' !== $data->startDate || '' !== $data->startTime) {
            $startsAt = $this->instant('start', $data->startDate, $data->startTime, $data->startOccurrence, $timezone, required: true, errors: $errors, choices: $choices);
        }
        $endsAt = $this->instant('end', $data->endDate, $data->endTime, $data->endOccurrence, $timezone, required: false, errors: $errors, choices: $choices);

        if ([] !== $errors) {
            return new FormTranslation(null, $errors, $choices);
        }

        return new FormTranslation(new UpdateEventCommand(
            $name,
            $data->description,
            $data->instructions,
            $startsAt,
            $endsAt,
            null === $endsAt, // fin laissée vide : elle est effacée (la valeur « inchangée » vient du champ prérempli)
            '' === $data->type ? null : $data->type,
            null === $postedTimezone ? null : $postedTimezone->identifier(),
        ));
    }

    /**
     * Saisies préremplies d'un événement existant : horaires dans le fuseau de l'événement ; pour un horaire répété,
     * l'occurrence actuelle est retrouvée pour ne pas redemander un choix déjà fait.
     */
    public function prefill(EventDetail $detail): EventFormData
    {
        $timezone = EventTimezone::fromIdentifier($detail->timezone);
        [$startDate, $startTime, $startOccurrence] = $this->local($detail->startsAt, $timezone);
        [$endDate, $endTime, $endOccurrence] = null === $detail->endsAt ? ['', '', ''] : $this->local($detail->endsAt, $timezone);

        return new EventFormData(
            $detail->name,
            $detail->typeCode,
            $detail->description ?? '',
            $detail->operationalInstructions ?? '',
            $startDate,
            $startTime,
            $endDate,
            $endTime,
            $detail->timezone,
            $startOccurrence,
            $endOccurrence,
        );
    }

    /**
     * @return array{string, string, string} date, heure locales et occurrence (si l'horaire est répété)
     */
    private function local(\DateTimeImmutable $instant, EventTimezone $timezone): array
    {
        $local = $instant->setTimezone($timezone->toDateTimeZone());
        $date = $local->format('Y-m-d');
        $time = $local->format('H:i');
        $occurrence = '';
        $candidates = $this->resolver->candidates(LocalDate::fromString($date), LocalTime::fromString($time), $timezone);
        if (\count($candidates) > 1) {
            $occurrence = $candidates[0]->getTimestamp() === $instant->getTimestamp() ? Occurrence::First->value : Occurrence::Second->value;
        }

        return [$date, $time, $occurrence];
    }

    /**
     * @param array<string, string> $errors
     */
    private function name(EventFormData $data, array &$errors): string
    {
        $name = trim($data->name);
        if ('' === $name) {
            $errors['name'] = 'Indiquez le nom de l’événement.';
        } elseif (mb_strlen($name) > Event::NAME_MAX_LENGTH) {
            $errors['name'] = sprintf('Le nom ne peut pas dépasser %d caractères.', Event::NAME_MAX_LENGTH);
        }

        return $name;
    }

    /**
     * @param array<string, string> $errors
     */
    private function timezone(string $identifier, array &$errors, bool $required): ?EventTimezone
    {
        if ('' === $identifier) {
            if ($required) {
                $errors['timezone'] = 'Choisissez le fuseau horaire de l’événement.';
            }

            return null;
        }
        try {
            return EventTimezone::fromIdentifier($identifier);
        } catch (DomainRuleViolation) {
            $errors['timezone'] = 'Ce fuseau horaire n’est pas pris en charge.';

            return null;
        }
    }

    /**
     * Instant UTC d'une date et d'une heure locales (champs liés : les deux ou aucun).
     *
     * @param array<string, string>                                    $errors
     * @param array<string, list<array{value: string, label: string}>> $choices
     */
    private function instant(string $key, string $date, string $time, string $occurrence, EventTimezone $timezone, bool $required, array &$errors, array &$choices): ?\DateTimeImmutable
    {
        if ('' === $date && '' === $time) {
            return null;
        }
        $label = 'start' === $key ? 'de début' : 'de fin';
        $valid = true;
        if ('' === $date) {
            $errors[$key.'_date'] = sprintf('Indiquez la date %s.', $label);
            $valid = false;
        }
        if ('' === $time) {
            $errors[$key.'_time'] = sprintf('Indiquez l’heure %s.', $label);
            $valid = false;
        }
        if (!$valid) {
            return null;
        }
        try {
            $localDate = LocalDate::fromString($date);
        } catch (DomainRuleViolation) {
            $errors[$key.'_date'] = 'Date invalide : saisissez une date existante.';

            return null;
        }
        try {
            $localTime = LocalTime::fromString($time);
        } catch (DomainRuleViolation) {
            $errors[$key.'_time'] = 'Heure invalide : saisissez une heure au format HH:MM.';

            return null;
        }

        try {
            return $this->resolver->resolve($localDate, $localTime, $timezone, Occurrence::tryFrom($occurrence));
        } catch (NonexistentLocalTime $e) {
            $errors[$key.'_time'] = $e->getMessage();
        } catch (AmbiguousLocalTime $e) {
            $errors[$key.'_occurrence'] = $e->getMessage();
            $choices[$key] = array_map(
                static fn (\DateTimeImmutable $candidate, int $index): array => [
                    'value' => 0 === $index ? Occurrence::First->value : Occurrence::Second->value,
                    'label' => sprintf('%s occurrence (UTC%s)', 0 === $index ? 'Première' : 'Seconde', $candidate->setTimezone($timezone->toDateTimeZone())->format('P')),
                ],
                $this->resolver->candidates($localDate, $localTime, $timezone),
                array_keys($this->resolver->candidates($localDate, $localTime, $timezone)),
            );
        }

        return null;
    }
}
