<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

/**
 * Saisies brutes du formulaire d'événement (chaînes), conservées telles quelles pour réafficher le formulaire en cas
 * d'erreur. Aucune valeur n'est interprétée ici : c'est le rôle du traducteur ; une valeur absente ou qui n'est pas
 * une chaîne (tableau forgé) vaut la chaîne vide.
 */
final readonly class EventFormData
{
    public function __construct(
        public string $name = '',
        public string $type = '',
        public string $description = '',
        public string $instructions = '',
        public string $startDate = '',
        public string $startTime = '',
        public string $endDate = '',
        public string $endTime = '',
        public string $timezone = '',
        public string $startOccurrence = '',
        public string $endOccurrence = '',
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $text = static fn (string $key): string => \is_string($post[$key] ?? null) ? $post[$key] : '';

        return new self(
            $text('name'),
            $text('type'),
            $text('description'),
            $text('instructions'),
            $text('start_date'),
            $text('start_time'),
            $text('end_date'),
            $text('end_time'),
            $text('timezone'),
            $text('start_occurrence'),
            $text('end_occurrence'),
        );
    }
}
