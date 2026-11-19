<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Second facteur TOTP d'un compte CWS à privilèges élevés (SEC-006, LOT 11). Un seul par compte. Le secret n'est JAMAIS stocké en clair (chiffrement authentifié
 * lié au compte, voir `TwoFactorSecretCipher`) ; tant que `confirmedAt` est nul, l'inscription est en cours et ne protège encore rien. `lastUsedStep`
 * interdit de rejouer un code déjà accepté (ou plus ancien) dans la fenêtre de tolérance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_two_factor')]
#[ORM\UniqueConstraint(name: 'uniq_two_factor_user', columns: ['user_id'])]
class TwoFactorCredential
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $lastUsedStep = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $encryptedSecret,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function encryptedSecret(): string
    {
        return $this->encryptedSecret;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function lastUsedStep(): ?int
    {
        return null === $this->lastUsedStep ? null : (int) $this->lastUsedStep;
    }

    /** Remplace le secret d'une inscription NON confirmée (rechargement de la page d'activation après abandon). */
    public function replacePendingSecret(string $encryptedSecret): void
    {
        if ($this->isConfirmed()) {
            throw new \LogicException('Le secret d\'un second facteur confirmé ne se remplace pas : le réinitialiser d\'abord.');
        }
        $this->encryptedSecret = $encryptedSecret;
    }

    public function confirm(\DateTimeImmutable $now, int $step): void
    {
        $this->confirmedAt = $now;
        $this->lastUsedStep = (string) $step;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
