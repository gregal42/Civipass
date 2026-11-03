<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Accès commercial accordé par CWS à une commune, sans abonnement payant (DM-076, DT-030).
 *
 * Par commune, expiration facultative, révocable, historique conservé : jamais supprimé physiquement (FK RESTRICT,
 * aucune méthode de suppression). Une commune peut recevoir plusieurs accès successifs. Seuls des super-administrateurs
 * CWS attribuent ou révoquent ; l'administration communale n'y a aucun accès. L'attribution et la révocation passent
 * par le back-office CWS (lot Administration CWS) avec audit dans la même transaction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'municipality_commercial_access')]
#[ORM\Index(name: 'idx_commercial_access_municipality', columns: ['municipality_id', 'granted_at'])]
class MunicipalityCommercialAccess
{
    public const MAX_REASON_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'revoked_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'granted_by', nullable: false, onDelete: 'RESTRICT')]
        private User $grantedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $grantedAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
        string $reason,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if (!$grantedBy->isSuperAdmin()) {
            throw new DomainRuleViolation('Seul un super-administrateur CWS peut accorder un accès commercial.');
        }
        if (null !== $expiresAt && $expiresAt <= $grantedAt) {
            throw new DomainRuleViolation('La date d\'expiration doit être postérieure à la date d\'attribution.');
        }
        $this->reason = self::normalizeReason($reason);
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

    public function grantedBy(): User
    {
        return $this->grantedBy;
    }

    public function grantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /**
     * Valide à l'instant donné : attribué, ni expiré ni révoqué. Le statut administratif de la commune
     * est vérifié séparément (EventCreationPolicy).
     */
    public function isValidAt(\DateTimeImmutable $at): bool
    {
        return $this->grantedAt <= $at
            && (null === $this->expiresAt || $at < $this->expiresAt)
            && (null === $this->revokedAt || $at < $this->revokedAt);
    }

    /**
     * Révocation définitive : l'accès reste en base pour l'historique ; un nouvel accès se crée séparément.
     */
    public function revoke(User $by, \DateTimeImmutable $now): void
    {
        if (!$by->isSuperAdmin()) {
            throw new DomainRuleViolation('Seul un super-administrateur CWS peut révoquer un accès commercial.');
        }
        if ($this->isRevoked()) {
            throw new DomainRuleViolation('Cet accès commercial est déjà révoqué.');
        }
        $this->revokedAt = $now;
        $this->revokedBy = $by;
    }

    private static function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new DomainRuleViolation('Une justification est obligatoire.');
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new DomainRuleViolation(sprintf('Justification trop longue (%d caractères maximum).', self::MAX_REASON_LENGTH));
        }

        return $reason;
    }
}
