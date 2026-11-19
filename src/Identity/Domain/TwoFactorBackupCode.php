<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Code de secours à usage unique d'un second facteur (SEC-006). Seule l'empreinte (HMAC-SHA-256 avec une clé dérivée du secret applicatif) est stockée ;
 * la consommation est atomique (`TwoFactorRepository::consumeBackupCode`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_two_factor_backup_code')]
#[ORM\UniqueConstraint(name: 'uniq_two_factor_backup_hash', columns: ['code_hash'])]
class TwoFactorBackupCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TwoFactorCredential::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'credential_id', nullable: false, onDelete: 'RESTRICT')]
        private TwoFactorCredential $credential,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $codeHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function credential(): TwoFactorCredential
    {
        return $this->credential;
    }

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    /** En production la consommation passe par une mise à jour SQL conditionnelle atomique (`TwoFactorRepository::consumeBackupCode`) ; cette méthode sert à la cohérence du modèle. */
    public function markUsed(\DateTimeImmutable $now): void
    {
        $this->usedAt ??= $now;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
