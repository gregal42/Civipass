<?php

declare(strict_types=1);

namespace App\Control\Domain;

use App\Event\Domain\Event;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Code d'activation d'appareils de contrôle (LOT 09, DM-131) : créé par un administrateur communal pour SA commune et UN événement ; valable quelques
 * minutes, pour un nombre borné d'appareils. Seule l'empreinte du code est stockée. Il porte les paramètres de confiance (seuils, mode d'assurance) de tous les
 * appareils qui s'enregistrent avec lui. L'usage est consommé par une mise à jour conditionnelle ATOMIQUE (voir le dépôt) : jamais de lecture puis écriture.
 *
 * Commune et événement ne changent jamais : un appareil les lit TOUJOURS ici (source unique, aucune duplication à garder cohérente).
 */
#[ORM\Entity]
#[ORM\Table(name: 'control_activation')]
#[ORM\UniqueConstraint(name: 'uniq_control_activation_code_hash', columns: ['code_hash'])]
#[ORM\Index(name: 'idx_control_activation_scope', columns: ['municipality_id', 'event_id', 'created_at'])]
class ControlActivation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $usesCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'revoked_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\ManyToOne(targetEntity: Event::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $codeHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        private int $maxDevices,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private int $trustWarnSeconds,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        private int $trustHardSeconds,
        #[ORM\Column(type: Types::STRING, length: 10, enumType: AssuranceMode::class)]
        private AssuranceMode $assuranceMode,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
        private User $createdBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($maxDevices < 1 || $maxDevices > 10) {
            throw new DomainRuleViolation('Le nombre d’appareils doit être compris entre 1 et 10.');
        }
        if ($trustWarnSeconds < 1 || $trustWarnSeconds > $trustHardSeconds || $trustHardSeconds > 86_400) {
            throw new DomainRuleViolation('Seuils de confiance incohérents (avertissement ≤ seuil dur ≤ 24 h).');
        }
        if ($expiresAt <= $createdAt) {
            throw new DomainRuleViolation('Le code doit expirer après sa création.');
        }
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

    public function event(): Event
    {
        return $this->event;
    }

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function maxDevices(): int
    {
        return $this->maxDevices;
    }

    public function usesCount(): int
    {
        return $this->usesCount;
    }

    public function trustWarnSeconds(): int
    {
        return $this->trustWarnSeconds;
    }

    public function trustHardSeconds(): int
    {
        return $this->trustHardSeconds;
    }

    public function assuranceMode(): AssuranceMode
    {
        return $this->assuranceMode;
    }

    public function createdBy(): User
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    /** Annulation (même règle que la mise à jour conditionnelle atomique du dépôt) : un code déjà annulé ou expiré ne s'annule plus. */
    public function markRevoked(User $by, \DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt || $this->expiresAt <= $now) {
            throw new DomainRuleViolation('Ce code n’est plus utilisable : il est déjà expiré ou annulé.');
        }
        $this->revokedAt = $now;
        $this->revokedBy = $by;
    }

    /** Code encore utilisable à cet instant (non annulé, non expiré, appareils restants). */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && $this->expiresAt > $now && $this->usesCount < $this->maxDevices;
    }
}
