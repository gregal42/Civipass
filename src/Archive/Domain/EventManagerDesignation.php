<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use App\Event\Domain\EventParticipation;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Désignation d'un `EVENT_MANAGER` pour l'accès aux archives d'UN événement et d'UNE commune (option B, DM-120 D2) : rattachée à la PARTICIPATION. Une désignation
 * révoquée est DÉFINITIVE (aucune réactivation) ; une nouvelle désignation est un nouvel acte de l'administrateur. Une seule désignation ACTIVE par
 * (participation, utilisateur) : `active_marker` vaut 1 tant qu'elle est active, NULL ensuite (MySQL n'a pas d'index unique partiel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'archive_manager_designation')]
#[ORM\UniqueConstraint(name: 'uniq_archive_designation_active', columns: ['participation_id', 'user_id', 'active_marker'])]
#[ORM\Index(name: 'idx_archive_designation_user', columns: ['user_id', 'active_marker'])]
class EventManagerDesignation
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
        #[ORM\ManyToOne(targetEntity: EventParticipation::class)]
        #[ORM\JoinColumn(name: 'participation_id', nullable: false, onDelete: 'RESTRICT')]
        private EventParticipation $participation,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'designated_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $designatedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $designatedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function participation(): EventParticipation
    {
        return $this->participation;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function designatedBy(): User
    {
        return $this->designatedBy;
    }

    public function designatedAt(): \DateTimeImmutable
    {
        return $this->designatedAt;
    }

    public function isActive(): bool
    {
        return null !== $this->activeMarker && null === $this->revokedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function revocationCause(): ?RevocationCause
    {
        return $this->revocationCause;
    }

    /** @throws DomainRuleViolation si déjà révoquée (une révocation est définitive) */
    public function revoke(RevocationCause $cause, ?User $by, \DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new DomainRuleViolation('Cette désignation est déjà révoquée.');
        }
        $this->activeMarker = null;
        $this->revokedAt = $now;
        $this->revokedBy = $by;
        $this->revocationCause = $cause;
    }
}
