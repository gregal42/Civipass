<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\UpdateEventCommand;

/**
 * Résultat de la traduction d'un formulaire : la commande immuable du moteur métier, ou les erreurs de saisie par champ.
 * `choices` liste les horaires ambigus (retour à l'heure d'hiver) pour lesquels l'utilisateur doit choisir l'occurrence
 * (DM-055) : clé `start` ou `end`, options { value, label }.
 */
final readonly class FormTranslation
{
    /**
     * @param array<string, string>                                    $errors  champ => message
     * @param array<string, list<array{value: string, label: string}>> $choices
     */
    public function __construct(
        public CreateEventCommand|UpdateEventCommand|null $command,
        public array $errors = [],
        public array $choices = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors && null !== $this->command;
    }
}
