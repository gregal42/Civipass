<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Entrée de EventZoneService::update : `null` = inchangé ; une description vide l'efface. Le type est immuable (DM-080) :
 * il n'est volontairement pas modifiable ici.
 */
final readonly class UpdateZoneCommand
{
    public function __construct(
        public ?string $label = null,
        public ?string $description = null,
    ) {
    }
}
