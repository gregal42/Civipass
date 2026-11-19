<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de premier administrateur communal issue d'un lien d'invitation personnel (SEC-016). Le compte est créé, l'attestation du demandeur est conservée,
 * mais AUCUN droit n'est attribué : un super-administrateur CWS décide (approbation ou refus) après avoir vérifié la légitimité du demandeur HORS Civipass,
 * selon la procédure documentée (docs/runbooks/validation-administrateur-communal.md). La décision conserve le valideur, la date et le motif.
 * Un seul lien (usage unique) produit au plus une demande.
 */
#[ORM\Entity]
#[ORM\Table(name: 'onboarding_administrator_validation')]
#[ORM\UniqueConstraint(name: 'uniq_administrator_validation_link', columns: ['link_id'])]
#[ORM\Index(name: 'idx_administrator_validation_status', columns: ['status', 'requested_at'])]
class AdministratorValidation
{
    public const MIN_REASON = 10;
    public const MAX_REASON = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: AdministratorValidationStatus::class)]
    private AdministratorValidationStatus $status = AdministratorValidationStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'decided_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::STRING, length: self::MAX_REASON, nullable: true)]
    private ?string $decisionReason = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: InvitationLink::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'link_id', nullable: false, onDelete: 'RESTRICT')]
        private InvitationLink $link,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $requestedAt,
    ) {
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

    public function user(): User
    {
        return $this->user;
    }

    public function link(): InvitationLink
    {
        return $this->link;
    }

    public function status(): AdministratorValidationStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AdministratorValidationStatus::Pending === $this->status;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function decidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function decisionReason(): ?string
    {
        return $this->decisionReason;
    }

    /** Motif nettoyé et borné : obligatoire, il consigne COMMENT la légitimité a été vérifiée (appel à la mairie, courrier, justificatif…). */
    public static function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < self::MIN_REASON || mb_strlen($reason) > self::MAX_REASON) {
            throw new DomainRuleViolation(sprintf('Le motif est obligatoire (%d à %d caractères) : indiquez comment la légitimité a été vérifiée.', self::MIN_REASON, self::MAX_REASON));
        }

        return $reason;
    }

    /** @internal appelé par AdministratorValidationService */
    public function decide(AdministratorValidationStatus $status, User $by, string $reason, \DateTimeImmutable $at): void
    {
        if (!$this->isPending() || AdministratorValidationStatus::Pending === $status) {
            throw new DomainRuleViolation('Cette demande a déjà été traitée.');
        }
        $this->status = $status;
        $this->decidedBy = $by;
        $this->decisionReason = self::cleanReason($reason);
        $this->decidedAt = $at;
    }
}
