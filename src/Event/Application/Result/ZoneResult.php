<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\ZoneStatus;

/** État d'une zone après une opération individuelle ; `changed` = false : rien n'a été écrit ni audité. */
final readonly class ZoneResult
{
    public function __construct(
        public string $zoneId,
        public string $participationId,
        public string $municipalityId,
        public ZoneStatus $status,
        public bool $confirmed,
        public bool $deleted,
        public ValidityMode $validityMode,
        public bool $changed,
    ) {
    }
}
