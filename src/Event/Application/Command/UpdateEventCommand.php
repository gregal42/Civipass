<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Entrée de EventLifecycleService::update. `null` = inchangé ; pour les textes, une chaîne vide efface la valeur ;
 * `clearEndsAt` supprime la date de fin (sans fin). Aucun champ modifiable au-delà de ceux du domaine : nom, description,
 * consignes, dates, type et fuseau (ces trois derniers seulement en brouillon, DM-071).
 */
final readonly class UpdateEventCommand
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $operationalInstructions = null,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public bool $clearEndsAt = false,
        public ?string $typeCode = null,
        public ?string $timezone = null,
    ) {
    }
}
