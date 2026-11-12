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
        public \DateTimeImmutable $archiveClosesAt,
        public bool $viaDesignation,
    ) {
    }
}
