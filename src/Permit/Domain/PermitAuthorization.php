<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventParticipation;
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
    // @phpstan-ignore property.unusedType (assigné par les révisions du LOT 05.5 ; hydraté par Doctrine)
    private ?\DateTimeImmutable $revokedAt = null;

    /** @var Collection<int, PermitAuthorizationZone> */
    #[ORM\OneToMany(targetEntity: PermitAuthorizationZone::class, mappedBy: 'authorization', cascade: ['persist'])]
    private Collection $zones;

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
    public function window(): GrantWindow
    {
        return new GrantWindow($this->durationMode, $this->validFrom, $this->validUntil);
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
