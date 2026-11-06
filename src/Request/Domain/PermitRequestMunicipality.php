<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\EventParticipation;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Part communale d'un dossier (DM-101) : porte l'état propre à une commune. Les décisions sont indépendantes par
 * commune (aucun statut global). Au LOT 03, seul `PENDING` est écrit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request_municipality')]
#[ORM\UniqueConstraint(name: 'uniq_request_municipality_request_participation', columns: ['request_id', 'participation_id'])]
#[ORM\Index(name: 'idx_request_municipality_municipality_status', columns: ['municipality_id', 'status'])]
class PermitRequestMunicipality
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
    private Municipality $municipality;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MunicipalRequestStatus::class)]
    private MunicipalRequestStatus $status = MunicipalRequestStatus::Pending;

    /** @var Collection<int, PermitRequestZone> */
    #[ORM\OneToMany(targetEntity: PermitRequestZone::class, mappedBy: 'requestMunicipality', cascade: ['persist'])]
    private Collection $zones;

    /**
     * @param list<\App\Event\Domain\EventZone> $zones
     *
     * @internal construit uniquement par PermitRequest, qui contrôle l'événement, les doublons et les zones proposables
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequest::class, inversedBy: 'municipalities', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequest $request,
        #[ORM\ManyToOne(targetEntity: EventParticipation::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'participation_id', nullable: false, onDelete: 'RESTRICT')]
        private EventParticipation $participation,
        array $zones,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if ([] === $zones) {
            throw new DomainRuleViolation('Chaque commune demandée doit comporter au moins une zone.');
        }
        $this->id = Uuid::v7();
        $this->municipality = $participation->municipality();
        $this->zones = new ArrayCollection();
        foreach ($zones as $zone) {
            $this->zones->add(new PermitRequestZone($this, $zone));
        }
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function request(): PermitRequest
    {
        return $this->request;
    }

    public function participation(): EventParticipation
    {
        return $this->participation;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function status(): MunicipalRequestStatus
    {
        return $this->status;
    }

    /**
     * @return list<PermitRequestZone>
     */
    public function zones(): array
    {
        return array_values($this->zones->toArray());
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
