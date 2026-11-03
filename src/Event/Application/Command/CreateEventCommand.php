<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Entrée de EventCreationService::create. Aucun champ n'est ajouté au-delà du modèle validé ; le fuseau est facultatif
 * (proposé selon la commune organisatrice) sauf quand il ne peut pas être résolu de façon fiable (ex. Polynésie).
 */
final readonly class CreateEventCommand
{
    public function __construct(
        public string $typeCode,
        public string $name,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt = null,
        public ?string $timezone = null,
        public ?string $description = null,
        public ?string $operationalInstructions = null,
    ) {
    }
}
