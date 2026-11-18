<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Attribution d'une habilitation CWS à un super-administrateur (LOT 08.6, DM-139, DM-074). Une ligne par attribution, conservée comme historique après
 * révocation. `activeKey` (égal à l'identifiant du bénéficiaire tant que l'attribution est active, nul ensuite) porte l'unicité « une seule attribution active par
 * compte et permission » (MySQL n'a pas d'index unique partiel). Invariants : auto-attribution interdite, attributeur et bénéficiaire super-administrateurs actifs,
 * motif obligatoire et borné. N'ouvre AUCUN droit communal (DM-005).
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_cws_permission')]
#[ORM\UniqueConstraint(name: 'uniq_cws_permission_active', columns: ['active_key', 'permission'])]
#[ORM\Index(name: 'idx_cws_permission_user', columns: ['user_id'])]
class CwsPermissionGrant
{
    public const REASON_MAX_LENGTH = 200;
    public const REASON_MIN_LENGTH = 10;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $activeKey;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'revoked_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: CwsPermission::class)]
        private CwsPermission $permission,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'granted_by', nullable: false, onDelete: 'RESTRICT')]
        private User $grantedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $grantedAt,
        #[ORM\Column(type: Types::STRING, length: self::REASON_MAX_LENGTH)]
        private string $reason,
    ) {
        $this->id = Uuid::v7();
        $this->activeKey = $user->id();
    }

    /**
     * @throws DomainRuleViolation auto-attribution, comptes non super-administrateurs ou inactifs, motif absent ou hors bornes
     */
    public static function grant(User $user, CwsPermission $permission, User $grantedBy, string $reason, \DateTimeImmutable $now): self
    {
        $reason = trim($reason);
        if ($user->id()->equals($grantedBy->id())) {
            throw new DomainRuleViolation('Nul ne peut s’attribuer une habilitation CWS à soi-même.');
        }
        if (!$user->isSuperAdmin() || !$user->isActive()) {
            throw new DomainRuleViolation('Le bénéficiaire doit être un super-administrateur actif.');
        }
        if (!$grantedBy->isSuperAdmin() || !$grantedBy->isActive()) {
            throw new DomainRuleViolation('L’attributeur doit être un super-administrateur actif.');
        }
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le motif d’attribution est obligatoire (%d à %d caractères).', self::REASON_MIN_LENGTH, self::REASON_MAX_LENGTH));
        }

        return new self($user, $permission, $grantedBy, $now, $reason);
    }

    public function revoke(User $by, \DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt) {
            throw new DomainRuleViolation('Cette attribution est déjà révoquée.');
        }
        $this->revokedAt = $now;
        $this->revokedBy = $by;
        $this->activeKey = null;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function permission(): CwsPermission
    {
        return $this->permission;
    }

    public function grantedBy(): User
    {
        return $this->grantedBy;
    }

    public function grantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isActive(): bool
    {
        return null !== $this->activeKey;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
