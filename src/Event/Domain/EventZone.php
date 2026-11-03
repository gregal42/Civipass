<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Event\Domain\Validity\ValidityMode;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Zone textuelle d'une participation communale : seul rattachement, la participation (DM-006). Aucune bibliothèque,
 * aucun instantané, aucune géométrie. Chaque commune administre uniquement les siennes (DM-007) : les droits
 * d'écriture (administrateur communal, commune active) sont contrôlés par les politiques d'accès.
 *
 * - Confirmation : `confirmedAt` (DM-051), distincte du statut. Une zone non confirmée n'ouvre aucun droit.
 * - Suppression : toujours logique, sans retour (DM-052) ; distincte de la suspension.
 * - Édition : participation ACTIVE ou SUSPENDED seulement (DM-058, DM-066).
 * Les créneaux (EventZoneValidity) sont gérés par les services : changer de mode de validité ne supprime rien ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_zone')]
#[ORM\Index(name: 'idx_event_zone_participation_status', columns: ['participation_id', 'status', 'deleted_at'])]
class EventZone
{
    public const LABEL_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: self::LABEL_MAX_LENGTH)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ZoneStatus::class)]
    private ZoneStatus $status = ZoneStatus::Active;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ValidityMode::class)]
    private ValidityMode $validityMode = ValidityMode::Unconfigured;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: EventParticipation::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'participation_id', nullable: false, onDelete: 'RESTRICT')]
        private EventParticipation $participation,
        string $label,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: ZoneType::class)]
        private ZoneType $type,
        ?string $description,
        ?\DateTimeImmutable $confirmedAt,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
        private User $createdBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->label = self::normalizeLabel($label);
        $this->description = self::nullIfBlank($description);
        $this->confirmedAt = $confirmedAt;
        $this->updatedAt = $createdAt;
    }

    /**
     * Zone créée manuellement par un administrateur communal : confirmée dès sa création (DM-051). Pour une
     * « Commune entière » manuelle, le périmètre doit être explicitement confirmé dans la commande.
     */
    public static function create(
        EventParticipation $participation,
        string $label,
        ZoneType $type,
        ?string $description,
        User $createdBy,
        \DateTimeImmutable $now,
        bool $wholeMunicipalityConfirmed = false,
    ): self {
        self::assertEditableParticipation($participation);
        if (ZoneType::WholeMunicipality === $type && !$wholeMunicipalityConfirmed) {
            throw new DomainRuleViolation('Le périmètre « Commune entière » doit être confirmé explicitement.');
        }

        return new self($participation, $label, $type, $description, $now, $createdBy, $now);
    }

    /**
     * Zone « Commune entière — [nom] » proposée automatiquement à la création d'une participation (DM-058) :
     * non confirmée, donc sans aucun droit ; possible même pour une participation INVITED ; non recréée si supprimée (DM-068).
     */
    public static function proposeWholeMunicipality(EventParticipation $participation, User $createdBy, \DateTimeImmutable $now): self
    {
        if (ParticipationStatus::Closed === $participation->status()) {
            throw new DomainRuleViolation('Cette participation est clôturée.');
        }

        return new self(
            $participation,
            sprintf('Commune entière — %s', $participation->municipality()->name()),
            ZoneType::WholeMunicipality,
            null,
            null,
            $createdBy,
            $now,
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function participation(): EventParticipation
    {
        return $this->participation;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): ZoneType
    {
        return $this->type;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function status(): ZoneStatus
    {
        return $this->status;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function validityMode(): ValidityMode
    {
        return $this->validityMode;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function createdBy(): User
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    /**
     * Zone comptant pour l'activation de l'événement (DM-059) : confirmée, active, non supprimée, validité configurée,
     * participation ACTIVE (une période future suffit). Pour SPECIFIC_PERIODS, l'existence d'au moins un créneau non
     * supprimé est vérifiée avec les créneaux (EventZoneRepository::existsActivationReadyZone).
     */
    public function satisfiesActivationRequirement(): bool
    {
        return $this->isConfirmed()
            && ZoneStatus::Active === $this->status
            && !$this->isDeleted()
            && ValidityMode::Unconfigured !== $this->validityMode
            && ParticipationStatus::Active === $this->participation->status();
    }

    /**
     * Prérequis d'accès portés par la zone et ses parents (jamais copiés) ; l'intervalle de validité est calculé
     * séparément par ZoneValidityCalculator (étape 1).
     */
    public function mayOpenRights(): bool
    {
        return $this->satisfiesActivationRequirement() && $this->participation->isOperational();
    }

    public function update(string $label, ?string $description, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->label = self::normalizeLabel($label);
        $this->description = self::nullIfBlank($description);
        $this->updatedAt = $now;
    }

    /** Idempotent : une zone déjà confirmée garde sa date de confirmation. */
    public function confirm(\DateTimeImmutable $now): void
    {
        $this->assertEditable();
        if (null === $this->confirmedAt) {
            $this->confirmedAt = $now;
            $this->updatedAt = $now;
        }
    }

    public function suspend(\DateTimeImmutable $now): void
    {
        $this->assertEditable();
        if (ZoneStatus::Suspended === $this->status) {
            throw new DomainRuleViolation('Cette zone est déjà suspendue.');
        }
        $this->status = ZoneStatus::Suspended;
        $this->updatedAt = $now;
    }

    public function reactivate(\DateTimeImmutable $now): void
    {
        $this->assertEditable();
        if (ZoneStatus::Active === $this->status) {
            throw new DomainRuleViolation('Cette zone est déjà active.');
        }
        $this->status = ZoneStatus::Active;
        $this->updatedAt = $now;
    }

    /**
     * Mode de configuration de la validité. Les créneaux existants sont traités par le service (suppression logique,
     * DM-075) : cette méthode ne supprime et ne crée rien.
     */
    public function changeValidityMode(ValidityMode $mode, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->validityMode = $mode;
        $this->updatedAt = $now;
    }

    /** Suppression logique définitive : la zone n'est jamais réactivée implicitement (DM-052). */
    public function markDeleted(\DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->deletedAt = $now;
        $this->updatedAt = $now;
    }

    private function assertEditable(): void
    {
        if ($this->isDeleted()) {
            throw new DomainRuleViolation('Cette zone est supprimée.');
        }
        self::assertEditableParticipation($this->participation);
    }

    private static function assertEditableParticipation(EventParticipation $participation): void
    {
        if (!$participation->allowsZoneEdition()) {
            throw new DomainRuleViolation('Les zones ne sont modifiables que lorsque la participation est active ou suspendue.');
        }
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le libellé de la zone est obligatoire (%d caractères maximum).', self::LABEL_MAX_LENGTH));
        }

        return $label;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
