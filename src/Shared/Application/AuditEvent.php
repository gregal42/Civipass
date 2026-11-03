<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Symfony\Component\Uid\Uuid;

final readonly class AuditEvent
{
    /**
     * @param array<string, mixed>|null $previousValue
     * @param array<string, mixed>      $newValue
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public AuditAction $action,
        public AuditSubjectType $subjectType,
        public Uuid $subjectId,
        public ?Uuid $municipalityId,
        public AuditActor $actor,
        public ?array $previousValue,
        public array $newValue,
        /** Regroupe les écritures d'une même opération (opérations groupées, clôtures) : DT-022. */
        public ?Uuid $batchId = null,
    ) {
    }
}
