<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

/**
 * Résultat de la création : identifiants et fuseau retenu, jamais d'entité Doctrine.
 */
final readonly class CreatedEvent
{
    public function __construct(
        public string $eventId,
        public string $publicReference,
        public string $participationId,
        public string $proposedZoneId,
        public string $timezone,
    ) {
    }
}
