<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use App\Event\Domain\Event;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Habilitation de COORDINATION STATISTIQUE (L5-36, arbitrage A1) : lecture de la ventilation du bilan de clôture par commune, pour un `EVENT_MANAGER` de la commune
 * ORGANISATRICE (le `MUNICIPALITY_ADMIN` l'a de plein droit). DISTINCTE de la désignation d'archive : l'une n'ouvre jamais l'autre, et aucune ne donne le droit de
 * lire un dossier. Mêmes règles de révocation : définitive, jamais réactivée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'archive_statistics_coordination')]
#[ORM\UniqueConstraint(name: 'uniq_archive_stats_coordination_active', columns: ['event_id', 'user_id', 'active_marker'])]
#[ORM\Index(name: 'idx_archive_stats_coordination_user', columns: ['user_id', 'active_marker'])]
class StatisticsCoordinationGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $activeMarker = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revoked_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $revokedBy = null;

    #[ORM\Column(type: Types::STRING, length: 24, nullable: true, enumType: RevocationCause::class)]
    private ?RevocationCause $revocationCause = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
        private Event $event,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'granted_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $grantedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $grantedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function grantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function grantedBy(): User
    {
        return $this->grantedBy;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function revocationCause(): ?RevocationCause
    {
        return $this->revocationCause;
    }

    public function isActive(): bool
    {
        return null !== $this->activeMarker && null === $this->revokedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(RevocationCause $cause, ?User $by, \DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new DomainRuleViolation('Cette habilitation est déjà révoquée.');
        }
        $this->activeMarker = null;
        $this->revokedAt = $now;
        $this->revokedBy = $by;
        $this->revocationCause = $cause;
    }
}
