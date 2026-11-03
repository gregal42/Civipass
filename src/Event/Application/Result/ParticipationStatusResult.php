<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\ParticipationStatus;

final readonly class ParticipationStatusResult
{
    public function __construct(
        public string $participationId,
        public string $municipalityId,
        public ParticipationStatus $status,
    ) {
    }
}
