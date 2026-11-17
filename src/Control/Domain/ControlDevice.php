<?php

declare(strict_types=1);

namespace App\Control\Domain;

use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Appareil de contrôle (LOT 09, DM-131) : AUCUNE donnée sur la personne du contrôleur (libellé d'appareil et plateforme grossière seulement). Enregistré
 * `PENDING` : aucun jeu de données ne lui est servi tant qu'un administrateur de la commune ne l'a pas APPROUVÉ en confirmant le code d'appariement affiché
 * sur le téléphone. Seule l'empreinte du secret d'appareil est stockée. La commune et l'événement sont ceux de l'activation (jamais dupliqués ici).
 *
 * Les transitions d'état s'effectuent par des mises à jour conditionnelles atomiques du dépôt (approbation concurrente : une seule gagne) ; les méthodes de
 * l'entité servent à construire l'objet et à lire son état. `expires_at` : jusqu'à l'approbation, échéance d'approbation ; ensuite, fin de vie de l'appareil.
 */
#[ORM\Entity]
#[ORM\Table(name: 'control_device')]
#[ORM\UniqueConstraint(name: 'uniq_control_device_secret_hash', columns: ['secret_hash'])]
#[ORM\Index(name: 'idx_control_device_activation', columns: ['activation_id', 'registered_at'])]
class ControlDevice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: DeviceStatus::class)]
    private DeviceStatus $status = DeviceStatus::Pending;

    #[ORM\Column(type: Types::STRING, length: 6, nullable: true, options: ['fixed' => true])]
    private ?string $pairingCode;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'approved_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
    private ?string $lastSnapshotHash = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $syncCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'deactivated_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $deactivatedBy = null;

    #[ORM\Column(type: Types::STRING, length: 12, nullable: true, enumType: DeactivationReason::class)]
    private ?DeactivationReason $deactivationReason = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ControlActivation::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'activation_id', nullable: false, onDelete: 'RESTRICT')]
        private ControlActivation $activation,
        #[ORM\Column(type: Types::STRING, length: 60)]
        private string $label,
        #[ORM\Column(type: Types::STRING, length: 10, enumType: DevicePlatform::class)]
        private DevicePlatform $platform,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $secretHash,
        string $pairingCode,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $registeredAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
    ) {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ('' === $label || mb_strlen($label) > 60) {
            throw new DomainRuleViolation('Indiquez un nom d’appareil de 1 à 60 caractères.');
        }
        if (1 !== preg_match('/^[0-9]{6}$/', $pairingCode)) {
            throw new DomainRuleViolation('Code d’appariement invalide.');
        }
        $this->label = $label;
        $this->pairingCode = $pairingCode;
        $this->id = Uuid::v7();
    }

    public static function newPairingCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function activation(): ControlActivation
    {
        return $this->activation;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function platform(): DevicePlatform
    {
        return $this->platform;
    }

    public function secretHash(): string
    {
        return $this->secretHash;
    }

    public function status(): DeviceStatus
    {
        return $this->status;
    }

    public function pairingCode(): ?string
    {
        return $this->pairingCode;
    }

    public function registeredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function approvedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approvedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function lastSnapshotHash(): ?string
    {
        return $this->lastSnapshotHash;
    }

    public function syncCount(): int
    {
        return $this->syncCount;
    }

    public function deactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function deactivationReason(): ?DeactivationReason
    {
        return $this->deactivationReason;
    }

    /**
     * Transitions du modèle de domaine. En exploitation, elles sont réalisées par des mises à jour conditionnelles ATOMIQUES du dépôt (approbation concurrente : une
     * seule gagne) ; ces méthodes expriment les mêmes règles sur l'entité (modèles en mémoire, tests) et ne sont jamais appelées sur une ligne déjà modifiée ailleurs.
     */
    public function markApproved(User $by, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        if (!$this->isAwaitingApproval($now)) {
            throw new DomainRuleViolation('Cet appareil n’est plus en attente d’approbation.');
        }
        $this->status = DeviceStatus::Active;
        $this->approvedAt = $now;
        $this->approvedBy = $by;
        $this->expiresAt = $expiresAt;
        $this->pairingCode = null;
    }

    public function markDeactivated(?User $by, DeactivationReason $reason, \DateTimeImmutable $now): void
    {
        if (DeviceStatus::Deactivated === $this->status) {
            throw new DomainRuleViolation('Cet appareil est déjà désactivé.');
        }
        $this->status = DeviceStatus::Deactivated;
        $this->deactivatedAt = $now;
        $this->deactivatedBy = $by;
        $this->deactivationReason = $reason;
        $this->pairingCode = null;
    }

    public function markRenewed(\DateTimeImmutable $newExpiresAt): void
    {
        if (DeviceStatus::Active !== $this->status) {
            throw new DomainRuleViolation('Seul un appareil approuvé peut être renouvelé.');
        }
        $this->expiresAt = $newExpiresAt;
    }

    public function markSynced(\DateTimeImmutable $now, string $snapshotHash): void
    {
        $this->lastSyncAt = $now;
        $this->lastSnapshotHash = $snapshotHash;
        ++$this->syncCount;
    }

    public function deactivatedBy(): ?User
    {
        return $this->deactivatedBy;
    }

    /** Appareil autorisé à recevoir des données : approuvé, non désactivé, non expiré. Seul état qui ouvre la synchronisation. */
    public function isOperational(\DateTimeImmutable $now): bool
    {
        return DeviceStatus::Active === $this->status && $this->expiresAt > $now;
    }

    /** En attente d'approbation et dans le délai. */
    public function isAwaitingApproval(\DateTimeImmutable $now): bool
    {
        return DeviceStatus::Pending === $this->status && $this->expiresAt > $now;
    }
}
