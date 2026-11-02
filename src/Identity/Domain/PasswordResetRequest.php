<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de réinitialisation. Conservée après usage (historique) : `usedAt` marque la consommation OU l'invalidation
 * par une demande plus récente. Seule l'empreinte du jeton est stockée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_password_reset_request')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_password_reset_user_created', columns: ['user_id', 'created_at'])]
class PasswordResetRequest
{
    public const LIFETIME = 'PT30M';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $tokenHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->expiresAt = $createdAt->add(new \DateInterval(self::LIFETIME));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Marque la demande comme utilisée ou invalidée. En production la consommation passe par une mise à jour SQL
     * conditionnelle atomique (PasswordResetRepository::consume) ; cette méthode sert à la cohérence du modèle.
     */
    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt ??= $now;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now < $this->expiresAt;
    }
}
