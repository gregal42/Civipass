<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Gel de conservation d'UN dossier communal (LOT 05.6.3, DM-120 D3) : un épisode = une ligne, conservée après la levée (historique). Un seul gel ACTIF par dossier
 * (`active_marker`). Le gel protège le dossier de la purge et maintient l'accès à l'archive de CE dossier ; il n'ouvre RIEN à une autre commune. Aucune
 * prolongation automatique : seule une revue explicite reporte l'échéance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'archive_legal_hold')]
#[ORM\UniqueConstraint(name: 'uniq_archive_hold_active', columns: ['request_municipality_id', 'active_marker'])]
#[ORM\Index(name: 'idx_archive_hold_review', columns: ['active_marker', 'review_due_at'])]
class RequestLegalHold
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $activeMarker = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_reviewed_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $lastReviewedBy = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $reviewCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'lifted_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $liftedBy = null;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $liftComment = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequestMunicipality::class)]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $dossier,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: LegalHoldReason::class)]
        private LegalHoldReason $reason,
        #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
        private ?string $comment,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'placed_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $placedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $placedAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $reviewDueAt,
    ) {
        if (LegalHoldReason::Other === $reason && (null === $comment || '' === trim($comment))) {
            throw new DomainRuleViolation('Un commentaire est obligatoire pour le motif « autre ».');
        }
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function dossier(): PermitRequestMunicipality
    {
        return $this->dossier;
    }

    public function reason(): LegalHoldReason
    {
        return $this->reason;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function placedBy(): User
    {
        return $this->placedBy;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function reviewDueAt(): \DateTimeImmutable
    {
        return $this->reviewDueAt;
    }

    public function reviewCount(): int
    {
        return $this->reviewCount;
    }

    public function lastReviewedAt(): ?\DateTimeImmutable
    {
        return $this->lastReviewedAt;
    }

    public function liftedAt(): ?\DateTimeImmutable
    {
        return $this->liftedAt;
    }

    public function lastReviewedBy(): ?User
    {
        return $this->lastReviewedBy;
    }

    public function liftedBy(): ?User
    {
        return $this->liftedBy;
    }

    public function liftComment(): ?string
    {
        return $this->liftComment;
    }

    public function isActive(): bool
    {
        return null !== $this->activeMarker && null === $this->liftedAt;
    }

    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return $this->isActive() && $this->reviewDueAt <= $now;
    }

    /** Revue explicite (« confirmer le maintien ») : reporte l'échéance ; jamais automatique. */
    public function review(User $by, \DateTimeImmutable $now, \DateTimeImmutable $nextDue): void
    {
        if (!$this->isActive()) {
            throw new DomainRuleViolation('Ce gel est levé : aucune revue possible.');
        }
        if ($nextDue <= $now) {
            throw new DomainRuleViolation('L\'échéance de revue doit être dans le futur.');
        }
        $this->lastReviewedAt = $now;
        $this->lastReviewedBy = $by;
        $this->reviewDueAt = $nextDue;
        ++$this->reviewCount;
    }

    public function lift(User $by, ?string $comment, \DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new DomainRuleViolation('Ce gel est déjà levé.');
        }
        $this->activeMarker = null;
        $this->liftedAt = $now;
        $this->liftedBy = $by;
        $this->liftComment = $comment;
    }
}
