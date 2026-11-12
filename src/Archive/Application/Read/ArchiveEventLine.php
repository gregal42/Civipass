<?php

declare(strict_types=1);

namespace App\Archive\Application\Read;

final readonly class ArchiveEventLine
{
    public function __construct(
        public string $eventId,
        public string $name,
        public string $publicReference,
        public \DateTimeImmutable $closedAt,
        /** Repère informatif d'éligibilité à la purge (jamais une fermeture d'accès). */
        public \DateTimeImmutable $purgeEligibleAt,
        public bool $viaDesignation,
    ) {
    }
}
