<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation d'un administrateur communal émise par CWS (LOT 07.1), distincte des liens d'invitation à un événement.
 *
 * Elle ne donne AUCUN droit par elle-même : l'appartenance n'est créée qu'à l'acceptation (aucun administrateur « fantôme »,
 * une invitation en attente ne compte jamais comme administrateur effectif). Seule l'empreinte du jeton est stockée ; l'adresse
 * du destinataire n'est jamais écrite dans l'audit ni les journaux. Conservée après usage : jamais supprimée (FK RESTRICT).
 * Un lien remplacé (renvoi) est révoqué ; `parent` chaîne les envois successifs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'municipality_admin_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_admin_invitation_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_admin_invitation_municipality', columns: ['municipality_id', 'created_at'])]
class MunicipalityAdminInvitation
{
    /** Durée par défaut (paramètre technique configurable : `app.admin_invitation_lifetime`). */
    public const DEFAULT_LIFETIME = 'P7D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'consumed_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $consumedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'revoked_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InvitationDeliveryStatus::class)]
    private InvitationDeliveryStatus $deliveryStatus = InvitationDeliveryStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryAttemptedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\Column(type: Types::STRING, length: 180)]
        private string $recipientEmail,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $tokenHash,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
        private User $createdBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        string $lifetime = self::DEFAULT_LIFETIME,
        #[ORM\ManyToOne(targetEntity: self::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'RESTRICT')]
        private ?self $parent = null,
    ) {
        $this->id = Uuid::v7();
        $this->expiresAt = $createdAt->add(new \DateInterval($lifetime));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    /** Jamais affichée en clair à un tiers, jamais écrite dans l'audit ni les journaux. */
    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdBy(): User
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function consumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function consumedBy(): ?User
    {
        return $this->consumedBy;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function deliveryStatus(): InvitationDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function deliveryAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->deliveryAttemptedAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** Utilisable : ni consommée, ni révoquée/remplacée, ni expirée. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && null === $this->revokedAt && !$this->isExpiredAt($now);
    }

    /** État tel que CWS doit le lire : PENDING | EXPIRED | USED | REVOKED. */
    public function stateAt(\DateTimeImmutable $now): string
    {
        return match (true) {
            null !== $this->consumedAt => 'USED',
            null !== $this->revokedAt => 'REVOKED',
            $this->isExpiredAt($now) => 'EXPIRED',
            default => 'PENDING',
        };
    }

    public function markDelivery(InvitationDeliveryStatus $status, \DateTimeImmutable $now): void
    {
        $this->deliveryStatus = $status;
        $this->deliveryAttemptedAt = $now;
    }

    /** Cohérence du modèle ; en production la consommation passe par une mise à jour SQL conditionnelle atomique. */
    public function markConsumed(User $by, \DateTimeImmutable $now): void
    {
        if (null === $this->consumedAt) {
            $this->consumedAt = $now;
            $this->consumedBy = $by;
        }
    }

    public function markRevoked(User $by, \DateTimeImmutable $now): void
    {
        if (null === $this->revokedAt && null === $this->consumedAt) {
            $this->revokedAt = $now;
            $this->revokedBy = $by;
        }
    }
}
