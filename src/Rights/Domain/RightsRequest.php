<?php

declare(strict_types=1);

namespace App\Rights\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de droits d'une personne (LOT 05.6.6, M9), reçue et traitée par UNE commune. Le registre ne contient AUCUNE donnée du demandeur (ni nom, ni adresse, ni e-mail) :
 * références, dates, codes et effectifs seulement. Les transitions (attestation, prorogation, décision, clôture, téléchargements) s'écrivent par SQL conditionnel sous verrou
 * dans les services, jamais par cette entité.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rights_request')]
#[ORM\UniqueConstraint(name: 'uniq_rights_request_reference', columns: ['public_reference'])]
#[ORM\Index(name: 'idx_rights_request_municipality', columns: ['municipality_id', 'status'])]
#[ORM\Index(name: 'idx_rights_request_due', columns: ['due_at'])]
class RightsRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: RightsRequestStatus::class)]
    private RightsRequestStatus $status = RightsRequestStatus::Received;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $extendedUntil = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: RightsExtensionReason::class)]
    private ?RightsExtensionReason $extensionReason = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $identityVerifiedAt = null; // @phpstan-ignore property.unusedType

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'identity_verified_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $identityVerifiedBy = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: RightsIdentityMethod::class)]
    private ?RightsIdentityMethod $identityMethod = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $conservationCheckedAt = null; // @phpstan-ignore property.unusedType

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'conservation_checked_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $conservationCheckedBy = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null; // @phpstan-ignore property.unusedType

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $decidedBy = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: RightsDecision::class)]
    private ?RightsDecision $decision = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: RightsRefusalReason::class)]
    private ?RightsRefusalReason $refusalReason = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $executedAt = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $exportAvailableUntil = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $exportDownloads = 0;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Municipality::class)]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\Column(type: Types::STRING, length: 20)]
        private string $publicReference,
        #[ORM\Column(type: Types::STRING, length: 24, enumType: RightsRequestKind::class)]
        private RightsRequestKind $kind,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $receivedAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'received_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $receivedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $dueAt,
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
        private int $dossierCount,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function publicReference(): string
    {
        return $this->publicReference;
    }

    public function kind(): RightsRequestKind
    {
        return $this->kind;
    }

    public function status(): RightsRequestStatus
    {
        return $this->status;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function dueAt(): \DateTimeImmutable
    {
        return $this->extendedUntil ?? $this->dueAt;
    }

    public function dossierCount(): int
    {
        return $this->dossierCount;
    }

    public function decision(): ?RightsDecision
    {
        return $this->decision;
    }

    public function exportDownloads(): int
    {
        return $this->exportDownloads;
    }

    public function receivedBy(): User
    {
        return $this->receivedBy;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function exportAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->exportAvailableUntil;
    }

    public function identityVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->identityVerifiedAt;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function executedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function conservationCheckedAt(): ?\DateTimeImmutable
    {
        return $this->conservationCheckedAt;
    }

    public function extensionReason(): ?RightsExtensionReason
    {
        return $this->extensionReason;
    }

    public function identityVerifiedBy(): ?User
    {
        return $this->identityVerifiedBy;
    }

    public function identityMethod(): ?RightsIdentityMethod
    {
        return $this->identityMethod;
    }

    public function conservationCheckedBy(): ?User
    {
        return $this->conservationCheckedBy;
    }

    public function decidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function refusalReason(): ?RightsRefusalReason
    {
        return $this->refusalReason;
    }
}
