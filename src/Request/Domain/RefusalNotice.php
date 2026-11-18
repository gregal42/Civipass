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
 * Information du demandeur refusé (LOT 08.5, DM-136) : UNE ligne par décision de refus, créée dans la transaction de la décision. Aucune donnée personnelle ici :
 * l'adresse, le motif et les noms sont relus au moment de l'envoi. Les transitions d'envoi (bail, réussite, réessai, abandon) sont des mises à jour
 * conditionnelles ATOMIQUES du dépôt, jamais des lectures puis écritures ; l'entité ne porte que la création et le marquage manuel par un agent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_refusal_notice')]
#[ORM\UniqueConstraint(name: 'uniq_refusal_notice_decision', columns: ['decision_id'])]
#[ORM\Index(name: 'idx_refusal_notice_dispatch', columns: ['status', 'available_at'])]
#[ORM\Index(name: 'idx_refusal_notice_municipality', columns: ['municipality_id', 'status'])]
class RefusalNotice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: RefusalNoticeStatus::class)]
    private RefusalNoticeStatus $status;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leasedUntil = null; // @phpstan-ignore property.unusedType (écrit par UPDATE conditionnel du dépôt)

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstAttemptAt = null; // @phpstan-ignore property.unusedType (écrit par UPDATE conditionnel du dépôt)

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $lastErrorCode = null; // @phpstan-ignore property.unusedType (écrit par UPDATE conditionnel du dépôt)

    #[ORM\Column(type: Types::STRING, length: 12, nullable: true, enumType: ManualChannel::class)]
    private ?ManualChannel $manualChannel = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'manual_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $manualBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $manualAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(targetEntity: PermitRequestDecision::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'decision_id', nullable: false, onDelete: 'RESTRICT')]
    private PermitRequestDecision $decision;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
    private Municipality $municipality;

    private function __construct(PermitRequestDecision $decision, Municipality $municipality, RefusalNoticeStatus $status, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->decision = $decision;
        $this->municipality = $municipality;
        $this->status = $status;
        $this->availableAt = $now;
        $this->createdAt = $now;
        if (RefusalNoticeStatus::NoRecipient === $status) {
            $this->processedAt = $now;
        }
    }

    /** @param bool $hasRecipient la demande porte une adresse e-mail (non vérifiée) : sinon l'état est définitif et visible des agents */
    public static function forDecision(PermitRequestDecision $decision, Municipality $municipality, bool $hasRecipient, \DateTimeImmutable $now): self
    {
        if (DecisionOutcome::Refused !== $decision->outcome()) {
            throw new DomainRuleViolation('Seul un refus donne lieu à une information du demandeur.');
        }

        return new self($decision, $municipality, $hasRecipient ? RefusalNoticeStatus::Pending : RefusalNoticeStatus::NoRecipient, $now);
    }

    /**
     * Un agent a informé le demandeur par un autre moyen (téléphone, guichet, courrier…). Seuls les états « à informer » (sans adresse ou échec définitif) le permettent.
     *
     * @throws DomainRuleViolation état incompatible
     */
    public function markInformed(ManualChannel $channel, User $by, \DateTimeImmutable $now): void
    {
        if (!$this->status->needsAgentAction()) {
            throw new DomainRuleViolation('Cette information n’attend pas d’action d’un agent.');
        }
        $this->status = RefusalNoticeStatus::Manual;
        $this->manualChannel = $channel;
        $this->manualBy = $by;
        $this->manualAt = $now;
        $this->processedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function status(): RefusalNoticeStatus
    {
        return $this->status;
    }

    public function decision(): PermitRequestDecision
    {
        return $this->decision;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function manualChannel(): ?ManualChannel
    {
        return $this->manualChannel;
    }

    public function manualAt(): ?\DateTimeImmutable
    {
        return $this->manualAt;
    }

    public function availableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function leasedUntil(): ?\DateTimeImmutable
    {
        return $this->leasedUntil;
    }

    public function firstAttemptAt(): ?\DateTimeImmutable
    {
        return $this->firstAttemptAt;
    }

    public function manualBy(): ?User
    {
        return $this->manualBy;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
