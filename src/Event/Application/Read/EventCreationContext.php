<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\AccessDenialReason;

/**
 * Ce dont le formulaire de création a besoin pour une commune : son nom, le droit de créer (décidé par la politique d'accès)
 * et le fuseau déduit de la commune quand les règles territoriales le permettent (null : l'utilisateur doit le choisir).
 */
final readonly class EventCreationContext
{
    public function __construct(
        public string $municipalityName,
        public bool $allowed,
        public ?AccessDenialReason $denial,
        public ?string $deducedTimezone,
    ) {
    }
}
