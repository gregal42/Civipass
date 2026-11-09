<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lien d'accès d'un bénéficiaire à son laissez-passer (LOT 05.2, DM-114). Plusieurs liens actifs peuvent coexister ; chacun se révoque
 * individuellement et explicitement. Seule l'EMPREINTE SHA-256 du secret est conservée. L'émetteur (agent, commune) n'est gardé que comme
 * identifiants, pour l'audit. Un lien n'a aucun effet sur le jeton de vérification du QR.
 *
 * Émetteur (LOT 05.3.2, D12) : un AGENT (identifiants de l'agent et de la commune obligatoires) ou le SYSTÈME (aucun identifiant ; lien créé pour
 * la notification automatique). Une révocation sans auteur est une révocation par le système.
 *
 * Utilisable ssi non révoqué ET non expiré (expiration : `AccessExpiry`, évaluée à l'usage par l'appelant qui connaît l'événement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_access_token')]
#[ORM\UniqueConstraint(name: 'uniq_permit_access_hash', columns: ['secret_hash'])]
#[ORM\Index(name: 'idx_permit_access_permit', columns: ['permit_id'])]
class PermitAccessToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
    private string $secretHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $revokedByUserId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Permit::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'permit_id', nullable: false, onDelete: 'RESTRICT')]
        private Permit $permit,
        AccessSecret $secret,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        /** Instantané de l'expiration connue à l'émission (informatif : voir AccessExpiry). */
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $issuedByUserId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $issuedByMunicipalityId,
        #[ORM\Column(type: Types::STRING, length: 10, enumType: IssuerType::class, options: ['default' => 'AGENT'])]
        private IssuerType $issuerType = IssuerType::Agent,
    ) {
        if (IssuerType::Agent === $issuerType && (null === $issuedByUserId || null === $issuedByMunicipalityId)) {
            throw new DomainRuleViolation('Un lien émis par un agent identifie l\'agent et la commune.');
        }
        if (IssuerType::System === $issuerType && (null !== $issuedByUserId || null !== $issuedByMunicipalityId)) {
            throw new DomainRuleViolation('Un lien émis par le système n\'a ni agent ni commune émettrice.');
        }
        $this->id = Uuid::v7();
        $this->secretHash = $secret->hash();
    }

    /** Lien créé par le système pour la notification automatique (LOT 05.3.2). */
    public static function issuedBySystem(Permit $permit, AccessSecret $secret, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $expiresAt): self
    {
        return new self($permit, $secret, $createdAt, $expiresAt, null, null, IssuerType::System);
    }

    /** Vrai si `$secret` est celui de ce lien (comparaison en temps constant des empreintes). */
    public function matches(AccessSecret $secret): bool
    {
        return hash_equals($this->secretHash, $secret->hash());
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function permit(): Permit
    {
        return $this->permit;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function issuerType(): IssuerType
    {
        return $this->issuerType;
    }

    public function isIssuedBySystem(): bool
    {
        return IssuerType::System === $this->issuerType;
    }

    public function issuedByUserId(): ?Uuid
    {
        return $this->issuedByUserId;
    }

    public function issuedByMunicipalityId(): ?Uuid
    {
        return $this->issuedByMunicipalityId;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /** Expiré à `$at` d'après la fin effective de l'événement (jamais la date enregistrée) ; intervalle semi-ouvert : expiré À l'instant exact. */
    public function isExpiredAt(\DateTimeImmutable $at): bool
    {
        $expiry = AccessExpiry::of($this->permit->event());

        return null !== $expiry && $at >= $expiry;
    }

    public function isUsableAt(\DateTimeImmutable $at): bool
    {
        return !$this->isRevoked() && !$this->isExpiredAt($at);
    }

    /** @throws DomainRuleViolation déjà révoqué */
    public function revoke(Uuid $byUserId, \DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            throw new DomainRuleViolation('Ce lien est déjà révoqué.');
        }
        $this->revokedAt = $now;
        $this->revokedByUserId = $byUserId;
    }

    /** Révocation par le système (lien automatique jamais transmis) : sans auteur. @throws DomainRuleViolation déjà révoqué */
    public function revokeBySystem(\DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            throw new DomainRuleViolation('Ce lien est déjà révoqué.');
        }
        $this->revokedAt = $now;
    }

    public function revokedByUserId(): ?Uuid
    {
        return $this->revokedByUserId;
    }
}
