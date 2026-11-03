<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Entrée d'audit immuable : aucune méthode de modification, aucune clé étrangère
 * (le module Shared ne dépend d'aucun autre module).
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_subject', columns: ['subject_type', 'subject_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_municipality', columns: ['municipality_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_audit_operator', columns: ['operator_id'])]
#[ORM\Index(name: 'idx_audit_batch', columns: ['batch_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * @param array<string, mixed>|null $previousValue
     * @param array<string, mixed>      $newValue
     */
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(type: Types::STRING, length: 60, enumType: AuditAction::class)]
        private AuditAction $action,
        #[ORM\Column(type: Types::STRING, length: 40, enumType: AuditSubjectType::class)]
        private AuditSubjectType $subjectType,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $subjectId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $municipalityId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $actorId,
        #[ORM\Column(type: Types::STRING, length: 16, enumType: AuditOrigin::class)]
        private AuditOrigin $origin,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $previousValue,
        #[ORM\Column(type: Types::JSON)]
        private array $newValue,
        /** Opérateur CWS réellement connecté, lorsqu'il diffère de l'utilisateur métier (actor_id) : DT-022. */
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $operatorId = null,
        /** Regroupe les écritures d'une même opération (opérations groupées, clôtures) : DT-022. */
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $batchId = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function subjectType(): AuditSubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function municipalityId(): ?Uuid
    {
        return $this->municipalityId;
    }

    public function actorId(): ?Uuid
    {
        return $this->actorId;
    }

    public function operatorId(): ?Uuid
    {
        return $this->operatorId;
    }

    public function batchId(): ?Uuid
    {
        return $this->batchId;
    }

    public function origin(): AuditOrigin
    {
        return $this->origin;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previousValue(): ?array
    {
        return $this->previousValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function newValue(): array
    {
        return $this->newValue;
    }
}
