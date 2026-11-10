<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Municipality\Domain\Municipality;
use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\DurationMode;
use App\Request\Domain\GrantWindow;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Autorisation d'UNE commune sur le laissez-passer : une par participation approuvée. Elle COPIE la fenêtre de la décision d'origine
 * (la décision, immuable, n'est jamais modifiée ; les prolongations du LOT 05.5 modifieront cette copie par révisions) et les zones
 * de la part. `revoked_at` est un fait administratif ; aucun état de validité n'est stocké (DM-112).
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_authorization')]
#[ORM\UniqueConstraint(name: 'uniq_permit_authorization_participation', columns: ['permit_id', 'participation_id'])]
#[ORM\UniqueConstraint(name: 'uniq_permit_authorization_decision', columns: ['decision_id'])]
class PermitAuthorization
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EventParticipation::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'participation_id', nullable: false, onDelete: 'RESTRICT')]
    private EventParticipation $participation;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
    private Municipality $municipality;

    #[ORM\OneToOne(targetEntity: PermitRequestDecision::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'decision_id', nullable: false, onDelete: 'RESTRICT')]
    private PermitRequestDecision $decision;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DurationMode::class)]
    private DurationMode $durationMode;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** @var Collection<int, PermitAuthorizationZone> */
    #[ORM\OneToMany(targetEntity: PermitAuthorizationZone::class, mappedBy: 'authorization', cascade: ['persist'])]
    private Collection $zones;

    /**
     * Périodes ANTÉRIEURES closes (LOT 05.5.2, B2) : les colonnes de l'autorisation sont la période COURANTE. Lues uniquement par `windows()`.
     *
     * @var Collection<int, PermitAuthorizationPeriod>
     */
    #[ORM\OneToMany(targetEntity: PermitAuthorizationPeriod::class, mappedBy: 'authorization', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $closedPeriods;

    /**
     * @internal construit par Permit::authorize()
     *
     * @throws DomainRuleViolation la décision n'est pas une approbation de cette part
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Permit::class, inversedBy: 'authorizations', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'permit_id', nullable: false, onDelete: 'RESTRICT')]
        private Permit $permit,
        PermitRequestMunicipality $part,
        PermitRequestDecision $decision,
    ) {
        $window = $decision->window();
        if (DecisionOutcome::Approved !== $decision->outcome() || null === $window || !$decision->requestMunicipality()->id()->equals($part->id())) {
            throw new DomainRuleViolation('Une autorisation ne naît que de l\'approbation de sa part communale.');
        }
        $this->id = Uuid::v7();
        $this->participation = $part->participation();
        $this->municipality = $part->municipality();
        $this->decision = $decision;
        $this->durationMode = $window->mode;
        $this->validFrom = $window->from;
        $this->validUntil = $window->until;
        $this->zones = new ArrayCollection();
        $this->closedPeriods = new ArrayCollection();
        foreach ($part->zones() as $requested) {
            $this->zones->add(new PermitAuthorizationZone($this, $requested->zone(), $requested->zoneLabel()));
        }
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function permit(): Permit
    {
        return $this->permit;
    }

    public function participation(): EventParticipation
    {
        return $this->participation;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function decision(): PermitRequestDecision
    {
        return $this->decision;
    }

    /** Fenêtre COURANTE de l'autorisation (copie de la décision tant qu'aucune révision n'existe). */
    /** Période COURANTE (la seule affichée au contrôle public). */
    public function window(): GrantWindow
    {
        return new GrantWindow($this->durationMode, $this->validFrom, $this->validUntil);
    }

    /**
     * TOUTES les périodes, dans l'ordre : les périodes closes, puis la courante. SEULE méthode autorisée à lire les périodes closes pour calculer
     * les droits (test d'architecture) : l'interruption entre deux périodes ne donne aucun droit.
     *
     * @return non-empty-list<GrantWindow>
     */
    public function windows(): array
    {
        $windows = array_map(static fn (PermitAuthorizationPeriod $p): GrantWindow => $p->window(), $this->closedPeriods->toArray());
        $windows[] = $this->window();

        return array_values($windows);
    }

    /**
     * Périodes closes, de la plus ancienne à la plus récente (historique de l'écran agent ; jamais montrées au contrôle public).
     *
     * @return list<PermitAuthorizationPeriod>
     */
    public function closedPeriods(): array
    {
        return array_values($this->closedPeriods->toArray());
    }

    /**
     * Change la FIN de la période courante (prolongation ou raccourcissement, LOT 05.5.2). Jamais le début, jamais une période close. Les règles
     * (bornes, événement, motif) sont celles du planificateur ; ici seules les invariants de la fenêtre et de la révocation sont garantis.
     *
     * @throws DomainRuleViolation autorisation révoquée, ou fenêtre invalide (fin non postérieure au début, mode incohérent)
     */
    public function changeCurrentEnd(DurationMode $mode, ?\DateTimeImmutable $until): void
    {
        $this->assertRevisable();
        $window = new GrantWindow($mode, $this->validFrom, $until); // valide : fin > début, mode « jusqu'à clôture » sans fin
        $this->durationMode = $window->mode;
        $this->validUntil = $window->until;
    }

    /**
     * REPRISE après expiration (D2) : la période courante, terminée STRICTEMENT avant `$at`, est archivée ; une nouvelle période courante commence à
     * `$at`. Aucune validité pendant l'interruption ; aucun chevauchement (la période archivée finit avant `$at`).
     *
     * @throws DomainRuleViolation révoquée, période courante non terminée, fenêtre invalide
     */
    public function resumeAt(\DateTimeImmutable $at, DurationMode $mode, ?\DateTimeImmutable $until, PermitRevision $closedBy, int $position): void
    {
        $this->assertRevisable();
        if (null === $this->validUntil || $this->validUntil >= $at) {
            throw new DomainRuleViolation('Une reprise exige une période courante terminée avant l\'instant de la reprise.');
        }
        $next = new GrantWindow($mode, $at, $until); // valide la nouvelle période avant toute modification
        $this->closedPeriods->add(new PermitAuthorizationPeriod($this, $position, $this->durationMode, $this->validFrom, $this->validUntil, $at, $closedBy));
        $this->durationMode = $next->mode;
        $this->validFrom = $next->from;
        $this->validUntil = $next->until;
    }

    /** Ligne de la zone dans cette autorisation (active OU retirée), ou nul. */
    public function zoneRow(Uuid $zoneId): ?PermitAuthorizationZone
    {
        foreach ($this->zones as $row) {
            if ($row->zone()->id()->equals($zoneId)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Nombre de zones RATTACHÉES (non retirées) : quel que soit l'état opérationnel de la zone de l'événement (une zone suspendue reste rattachée).
     */
    public function attachedZoneCount(): int
    {
        return \count(array_filter($this->zones->toArray(), static fn (PermitAuthorizationZone $z): bool => $z->isActive()));
    }

    /**
     * AJOUT d'une zone jamais rattachée (LOT 05.5.3). Une zone déjà présente (active ou retirée) ne se « rajoute » pas : on la réintègre.
     *
     * @throws DomainRuleViolation révoquée, ou zone déjà présente
     */
    public function addZone(EventZone $zone, string $label): PermitAuthorizationZone
    {
        $this->assertRevisable();
        if (null !== $this->zoneRow($zone->id())) {
            throw new DomainRuleViolation('Cette zone figure déjà dans l\'autorisation.');
        }
        $row = new PermitAuthorizationZone($this, $zone, $label);
        $this->zones->add($row);

        return $row;
    }

    /** @throws DomainRuleViolation révoquée, zone absente ou déjà retirée */
    public function retireZone(Uuid $zoneId, \DateTimeImmutable $at): void
    {
        $this->assertRevisable();
        ($this->zoneRow($zoneId) ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.'))->retire($at);
    }

    /** @throws DomainRuleViolation révoquée, zone absente ou non retirée */
    public function reinstateZone(Uuid $zoneId, string $currentLabel): void
    {
        $this->assertRevisable();
        ($this->zoneRow($zoneId) ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.'))->reinstate($currentLabel);
    }

    /** @throws DomainRuleViolation révoquée ou zone absente */
    public function relabelZone(Uuid $zoneId, string $currentLabel): void
    {
        $this->assertRevisable();
        ($this->zoneRow($zoneId) ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.'))->synchronizeLabel($currentLabel);
    }

    private function assertRevisable(): void
    {
        if (null !== $this->revokedAt) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : plus aucune révision n\'est possible.');
        }
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /**
     * Révocation (LOT 05.5.1, DM-116 §2) : irréversible, un seul instant. Réservée au service de révocation, qui écrit la révision et l'audit dans
     * la même transaction ; aucune méthode inverse n'existe.
     *
     * @throws DomainRuleViolation déjà révoquée
     */
    public function revoke(\DateTimeImmutable $at): void
    {
        if (null !== $this->revokedAt) {
            throw new DomainRuleViolation('Cette autorisation est déjà révoquée.');
        }
        $this->revokedAt = $at;
    }

    /**
     * Zones retirées (retrait logique), dans l'ordre du libellé : visibles de la vérification publique, jamais des droits ordinaires.
     *
     * @return list<PermitAuthorizationZone>
     */
    public function removedZones(): array
    {
        $zones = array_values(array_filter($this->zones->toArray(), static fn (PermitAuthorizationZone $z): bool => !$z->isActive()));
        usort($zones, static fn (PermitAuthorizationZone $a, PermitAuthorizationZone $b): int => [$a->zoneLabel(), $a->id()->toRfc4122()] <=> [$b->zoneLabel(), $b->id()->toRfc4122()]);

        return $zones;
    }

    /**
     * Zones autorisées non retirées, dans l'ordre du libellé.
     *
     * @return list<PermitAuthorizationZone>
     */
    public function activeZones(): array
    {
        $zones = array_values(array_filter($this->zones->toArray(), static fn (PermitAuthorizationZone $z): bool => $z->isActive()));
        usort($zones, static fn (PermitAuthorizationZone $a, PermitAuthorizationZone $b): int => [$a->zoneLabel(), $a->id()->toRfc4122()] <=> [$b->zoneLabel(), $b->id()->toRfc4122()]);

        return $zones;
    }
}
