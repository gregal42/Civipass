<?php

declare(strict_types=1);

namespace App\Retention\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registre MINIMAL des opérations de rétention (LOT 05.6.4, règle 8) : survit aux lignes supprimées, donc SANS clé étrangère, et ne porte AUCUNE donnée personnelle
 * (nature, identifiants d'événement, de commune, de dossier et de demande, références publiques d'événement, dates, comptes par table, exécutant éventuel). Une ligne
 * est écrite DANS la transaction de l'opération qu'elle décrit ; sa copie hors base est ajoutée APRÈS la validation (voir `PurgeRegisterFile`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'retention_purge_record')]
#[ORM\Index(name: 'idx_retention_record_event', columns: ['event_id', 'kind'])]
#[ORM\Index(name: 'idx_retention_record_municipality', columns: ['municipality_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_retention_record_kind', columns: ['kind', 'occurred_at'])]
class PurgeRecord
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @param array<string, int>|null $counts */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 20, enumType: PurgeRecordKind::class)]
        private PurgeRecordKind $kind,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $eventId,
        #[ORM\Column(type: Types::STRING, length: 30)]
        private string $eventReference,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $municipalityId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $dossierId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $requestId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $dueAt,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $actorUserId,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $counts,
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
        private int $filesDeleted,
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        private ?int $recipients,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $batchId,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function kind(): PurgeRecordKind
    {
        return $this->kind;
    }

    public function eventId(): Uuid
    {
        return $this->eventId;
    }

    public function eventReference(): string
    {
        return $this->eventReference;
    }

    public function municipalityId(): ?Uuid
    {
        return $this->municipalityId;
    }

    public function dossierId(): ?Uuid
    {
        return $this->dossierId;
    }

    public function requestId(): ?Uuid
    {
        return $this->requestId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function dueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function actorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    /** @return array<string, int>|null */
    public function counts(): ?array
    {
        return $this->counts;
    }

    public function filesDeleted(): int
    {
        return $this->filesDeleted;
    }

    public function recipients(): ?int
    {
        return $this->recipients;
    }

    public function batchId(): ?Uuid
    {
        return $this->batchId;
    }

    /**
     * Forme de la ligne de registre hors base (JSON) : identique à la ligne SQL, sans donnée personnelle.
     *
     * @return array<string, mixed>
     */
    public function toLine(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'kind' => $this->kind->value,
            'event' => $this->eventId->toRfc4122(),
            'eventReference' => $this->eventReference,
            'municipality' => $this->municipalityId?->toRfc4122(),
            'dossier' => $this->dossierId?->toRfc4122(),
            'request' => $this->requestId?->toRfc4122(),
            'occurredAt' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'dueAt' => $this->dueAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'actor' => $this->actorUserId?->toRfc4122(),
            'counts' => $this->counts,
            'files' => $this->filesDeleted,
            'recipients' => $this->recipients,
            'batch' => $this->batchId?->toRfc4122(),
        ];
    }
}
