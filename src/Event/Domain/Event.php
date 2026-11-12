<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Event\Domain\Time\EventTimezone;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Événement intercommunal : une seule commune organisatrice (DM-001), immuable.
 *
 * Verrouillé après activation (DM-071) : organisatrice, type, fuseau, référence publique, date de début.
 * Modifiables jusqu'à la clôture : nom, description, consignes, date de fin. Un événement clôturé est en lecture seule.
 * Les transitions sont celles de EventStatus ; les prérequis qui dépendent d'autres objets (zones pour l'activation,
 * participations pour la clôture, droits des acteurs) sont contrôlés par les services applicatifs (étape 5),
 * qui écrivent l'audit dans la même transaction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_event')]
#[ORM\UniqueConstraint(name: 'uniq_event_public_reference', columns: ['public_reference'])]
#[ORM\Index(name: 'idx_event_organizer_status', columns: ['organizer_municipality_id', 'status'])]
#[ORM\Index(name: 'idx_event_status_starts_at', columns: ['status', 'starts_at'])]
class Event
{
    public const NAME_MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['collation' => 'utf8mb4_bin'])]
    private string $publicReference;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'organizer_municipality_id', nullable: false, onDelete: 'RESTRICT')]
    private Municipality $organizerMunicipality;

    #[ORM\ManyToOne(targetEntity: EventType::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'type_id', nullable: false, onDelete: 'RESTRICT')]
    private EventType $type;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $operationalInstructions;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $timezone;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Draft;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suspensionReason = null;

    /** Date (UTC) de la dernière suspension connue (DM-081) ; avec suspensionReason, jamais la preuve d'une suspension en cours. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * Compteurs internes de clôture (LOT 05.6.2, M1) : jamais exposés directement, écrits UNE fois à la clôture ; NULL = non calculés (événement clos avant M1).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $closureStatistics = null;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicReference $publicReference,
        Municipality $organizerMunicipality,
        EventType $type,
        string $name,
        EventTimezone $timezone,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt,
        User $createdBy,
        \DateTimeImmutable $createdAt,
        ?string $description = null,
        ?string $operationalInstructions = null,
    ) {
        self::assertType($type);
        self::assertPeriod($startsAt, $endsAt);
        $this->id = Uuid::v7();
        $this->publicReference = $publicReference->value;
        $this->organizerMunicipality = $organizerMunicipality;
        $this->type = $type;
        $this->name = self::normalizeName($name);
        $this->description = self::nullIfBlank($description);
        $this->operationalInstructions = self::nullIfBlank($operationalInstructions);
        $this->timezone = $timezone->identifier();
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function publicReference(): PublicReference
    {
        return PublicReference::fromString($this->publicReference);
    }

    public function organizerMunicipality(): Municipality
    {
        return $this->organizerMunicipality;
    }

    public function type(): EventType
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function operationalInstructions(): ?string
    {
        return $this->operationalInstructions;
    }

    public function timezone(): EventTimezone
    {
        return EventTimezone::fromIdentifier($this->timezone);
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function status(): EventStatus
    {
        return $this->status;
    }

    /** Motif de la dernière suspension connue : jamais la preuve d'une suspension en cours (voir status()). */
    public function suspensionReason(): ?string
    {
        return $this->suspensionReason;
    }

    /** Date de la dernière suspension connue : jamais la preuve d'une suspension en cours (voir status()). */
    public function suspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function activatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    /** @throws DomainRuleViolation hors événement clos, ou compteurs déjà enregistrés (écriture unique : jamais de recalcul) */
    public function recordClosureStatistics(ClosureStatistics $statistics): void
    {
        if (!$this->status->isClosed()) {
            throw new DomainRuleViolation('Les compteurs de clôture ne s\'enregistrent que sur un événement clos.');
        }
        if (null !== $this->closureStatistics) {
            throw new DomainRuleViolation('Les compteurs de clôture sont déjà enregistrés : aucun recalcul.');
        }
        $this->closureStatistics = $statistics->toArray();
    }

    /** Compteurs EXACTS (version 1) ; `null` s'ils n'existent pas ou ont été remplacés par le résumé publié (version 2) à la purge du dernier dossier. */
    public function closureStatistics(): ?ClosureStatistics
    {
        return null === $this->closureStatistics || ClosureStatistics::VERSION !== ($this->closureStatistics['v'] ?? null) ? null : ClosureStatistics::fromArray($this->closureStatistics);
    }

    /** Résumé publié (version 2) : plus aucun effectif exact (LOT 05.6.4, L5-37). */
    public function closureSummary(): ?ClosureStatisticsSummary
    {
        return null === $this->closureStatistics || ClosureStatisticsSummary::VERSION !== ($this->closureStatistics['v'] ?? null) ? null : ClosureStatisticsSummary::fromArray($this->closureStatistics);
    }

    /**
     * Seule réécriture autorisée des compteurs : les compteurs exacts (version 1) sont REMPLACÉS par le résumé publié, une fois, à la purge du dernier dossier.
     *
     * @throws DomainRuleViolation hors événement clos, sans compteurs exacts (déjà réduits ou jamais calculés)
     */
    public function reduceClosureStatistics(ClosureStatisticsSummary $summary): void
    {
        if (!$this->status->isClosed() || null === $this->closureStatistics()) {
            throw new DomainRuleViolation('Seuls des compteurs exacts d\'un événement clos peuvent être réduits à leur forme publiée.');
        }
        $this->closureStatistics = $summary->toArray();
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function createdBy(): User
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOrganizedBy(Municipality $municipality): bool
    {
        return $this->organizerMunicipality->id()->equals($municipality->id());
    }

    /** Seul un événement actif ouvre des droits opérationnels (suspendu, brouillon et clôturé : aucun). */
    public function isOperational(): bool
    {
        return EventStatus::Active === $this->status;
    }

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->name = self::normalizeName($name);
        $this->updatedAt = $now;
    }

    public function describe(?string $description, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->description = self::nullIfBlank($description);
        $this->updatedAt = $now;
    }

    /** Consignes et informations opérationnelles : modifiables jusqu'à la clôture (DM-071). */
    public function changeOperationalInstructions(?string $instructions, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        $this->operationalInstructions = self::nullIfBlank($instructions);
        $this->updatedAt = $now;
    }

    /** Type verrouillé après activation. */
    public function changeType(EventType $type, \DateTimeImmutable $now): void
    {
        $this->assertDraft('type d\'événement');
        self::assertType($type);
        $this->type = $type;
        $this->updatedAt = $now;
    }

    /** Fuseau verrouillé après activation. */
    public function changeTimezone(EventTimezone $timezone, \DateTimeImmutable $now): void
    {
        $this->assertDraft('fuseau horaire');
        $this->timezone = $timezone->identifier();
        $this->updatedAt = $now;
    }

    /**
     * Dates : libres en brouillon ; après activation la date de début est verrouillée, la date de fin reste
     * modifiable et ne peut pas être antérieure à l'instant courant (sinon, clôturer l'événement).
     * Les effets sur les droits sont immédiats : ils sont calculés à la demande.
     */
    public function reschedule(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, \DateTimeImmutable $now): void
    {
        $this->assertEditable();
        self::assertPeriod($startsAt, $endsAt);
        if (EventStatus::Draft !== $this->status) {
            if ($startsAt != $this->startsAt) {
                throw new DomainRuleViolation('La date de début est verrouillée après l\'activation de l\'événement.');
            }
            if (null !== $endsAt && $endsAt <= $now) {
                throw new DomainRuleViolation('La nouvelle date de fin est déjà passée : clôturez l\'événement pour couper les accès.');
            }
        }
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->updatedAt = $now;
    }

    /**
     * Le prérequis « au moins une zone activable » (DM-059) est contrôlé par le service d'activation.
     */
    public function activate(\DateTimeImmutable $now): void
    {
        $this->transitionTo(EventStatus::Active);
        if (null === $this->activatedAt) {
            $this->activatedAt = $now;
        }
        $this->updatedAt = $now;
    }

    public function suspend(string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new DomainRuleViolation('Le motif de suspension est obligatoire.');
        }
        $this->transitionTo(EventStatus::Suspended);
        $this->suspensionReason = $reason;
        $this->suspendedAt = $now;
        $this->updatedAt = $now;
    }

    public function reactivate(\DateTimeImmutable $now): void
    {
        if (EventStatus::Suspended !== $this->status) {
            throw new DomainRuleViolation('Seul un événement suspendu peut être réactivé.');
        }
        $this->transitionTo(EventStatus::Active);
        // suspensionReason et suspendedAt restent « la dernière suspension connue » (DM-081) : seul le statut fait autorité.
        $this->updatedAt = $now;
    }

    /**
     * Clôture définitive, depuis DRAFT, ACTIVE ou SUSPENDED (DM-077). La clôture des participations dans la même
     * transaction et leurs prérequis relèvent du service de clôture.
     */
    public function close(\DateTimeImmutable $now): void
    {
        $this->transitionTo(EventStatus::Closed);
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    private function transitionTo(EventStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new DomainRuleViolation(sprintf('Transition d\'événement interdite : %s → %s.', $this->status->value, $target->value));
        }
        $this->status = $target;
    }

    private function assertEditable(): void
    {
        if ($this->status->isClosed()) {
            throw new DomainRuleViolation('Un événement clôturé est en lecture seule.');
        }
    }

    private function assertDraft(string $field): void
    {
        $this->assertEditable();
        if (EventStatus::Draft !== $this->status) {
            throw new DomainRuleViolation(sprintf('Le %s est verrouillé après l\'activation de l\'événement.', $field));
        }
    }

    private static function assertType(EventType $type): void
    {
        if (!$type->isActive()) {
            throw new DomainRuleViolation('Ce type d\'événement n\'est plus disponible.');
        }
    }

    private static function assertPeriod(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): void
    {
        if (null !== $endsAt && $endsAt <= $startsAt) {
            throw new DomainRuleViolation('La fin de l\'événement doit être postérieure à son début.');
        }
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le nom de l\'événement est obligatoire (%d caractères maximum).', self::NAME_MAX_LENGTH));
        }

        return $name;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
