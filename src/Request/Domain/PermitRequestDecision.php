<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Décision initiale d'une commune sur sa part de dossier (DM-106) : IMMUABLE (aucun mutateur, aucune suppression : FK RESTRICT)
 * et UNIQUE par part. Créée uniquement par `PermitRequestMunicipality::approve()` / `refuse()`, qui portent les transitions.
 * Les prolongations, révocations et réexamens du LOT 05 s'ajouteront à part, sans réécrire cette ligne.
 *
 * Distinguer : `decidedAt` (date de décision), `validFrom`/`validUntil` (période GLOBALE, fin nulle = jusqu'à clôture) et les
 * périodes d'accès par zone (calculées, jamais copiées ici).
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request_decision')]
#[ORM\UniqueConstraint(name: 'uniq_request_decision_part', columns: ['request_municipality_id'])]
#[ORM\Index(name: 'idx_request_decision_municipality_decided', columns: ['municipality_id', 'decided_at'])]
class PermitRequestDecision
{
    public const COMMENT_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
    private Municipality $municipality;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: DecisionOutcome::class)]
    private DecisionOutcome $outcome;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DecisionPath::class)]
    private DecisionPath $path;

    #[ORM\ManyToOne(targetEntity: RefusalReason::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'refusal_reason_id', nullable: true, onDelete: 'RESTRICT')]
    private ?RefusalReason $refusalReason = null;

    #[ORM\Column(type: Types::STRING, length: RefusalReason::LABEL_MAX_LENGTH, nullable: true)]
    private ?string $refusalReasonLabel = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $refusalReasonCode = null;

    #[ORM\Column(type: Types::STRING, length: self::COMMENT_MAX_LENGTH, nullable: true)]
    private ?string $refusalComment = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: DurationMode::class)]
    private ?DurationMode $durationMode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    private function __construct(
        #[ORM\OneToOne(targetEntity: PermitRequestMunicipality::class, inversedBy: 'decision', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $requestMunicipality,
        DecisionOutcome $outcome,
        DecisionPath $path,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $decidedAt,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'decided_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $decidedBy,
    ) {
        $this->id = Uuid::v7();
        $this->municipality = $requestMunicipality->municipality();
        $this->outcome = $outcome;
        $this->path = $path;
    }

    /** @internal appelé par PermitRequestMunicipality::approve() */
    public static function approved(PermitRequestMunicipality $part, GrantWindow $window, DecisionPath $path, \DateTimeImmutable $decidedAt, User $decidedBy): self
    {
        if ($window->from != $decidedAt) {
            throw new DomainRuleViolation('La période d\'autorisation commence à la décision.');
        }
        $decision = new self($part, DecisionOutcome::Approved, $path, $decidedAt, $decidedBy);
        $decision->durationMode = $window->mode;
        $decision->validFrom = $window->from;
        $decision->validUntil = $window->until;

        return $decision;
    }

    /** @internal appelé par PermitRequestMunicipality::refuse() */
    public static function refused(PermitRequestMunicipality $part, RefusalReason $reason, ?string $comment, DecisionPath $path, \DateTimeImmutable $decidedAt, User $decidedBy): self
    {
        if (!$reason->isActive()) {
            throw new DomainRuleViolation('Ce motif de refus n\'est plus proposé.');
        }
        $comment = null === $comment ? '' : trim($comment);
        if (mb_strlen($comment) > self::COMMENT_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le commentaire ne peut pas dépasser %d caractères.', self::COMMENT_MAX_LENGTH));
        }
        if ('' === $comment && $reason->needsDetail()) {
            throw new DomainRuleViolation('Un commentaire est obligatoire pour ce motif de refus.');
        }
        $decision = new self($part, DecisionOutcome::Refused, $path, $decidedAt, $decidedBy);
        $decision->refusalReason = $reason;
        $decision->refusalReasonCode = $reason->code();
        $decision->refusalReasonLabel = $reason->label();
        $decision->refusalComment = '' === $comment ? null : $comment;

        return $decision;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function requestMunicipality(): PermitRequestMunicipality
    {
        return $this->requestMunicipality;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function outcome(): DecisionOutcome
    {
        return $this->outcome;
    }

    public function path(): DecisionPath
    {
        return $this->path;
    }

    public function decidedAt(): \DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function decidedBy(): User
    {
        return $this->decidedBy;
    }

    public function refusalReason(): ?RefusalReason
    {
        return $this->refusalReason;
    }

    public function refusalReasonCode(): ?string
    {
        return $this->refusalReasonCode;
    }

    public function refusalReasonLabel(): ?string
    {
        return $this->refusalReasonLabel;
    }

    public function refusalComment(): ?string
    {
        return $this->refusalComment;
    }

    public function durationMode(): ?DurationMode
    {
        return $this->durationMode;
    }

    public function validFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    /** Fenêtre globale d'une approbation ; nulle pour un refus. */
    public function window(): ?GrantWindow
    {
        return null === $this->durationMode || null === $this->validFrom ? null : new GrantWindow($this->durationMode, $this->validFrom, $this->validUntil);
    }
}
