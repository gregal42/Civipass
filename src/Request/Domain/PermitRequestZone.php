<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\EventZone;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Zone demandée à une commune, rattachée à la part communale (jamais directement au dossier). Le libellé est copié :
 * la zone peut être renommée ensuite sans altérer l'historique du dossier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request_zone')]
#[ORM\UniqueConstraint(name: 'uniq_request_zone_part_zone', columns: ['request_municipality_id', 'zone_id'])]
class PermitRequestZone
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: EventZone::LABEL_MAX_LENGTH)]
    private string $zoneLabel;

    /**
     * @internal construit uniquement par PermitRequestMunicipality
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequestMunicipality::class, inversedBy: 'zones', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $requestMunicipality,
        #[ORM\ManyToOne(targetEntity: EventZone::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'zone_id', nullable: false, onDelete: 'RESTRICT')]
        private EventZone $zone,
    ) {
        $this->id = Uuid::v7();
        $this->zoneLabel = $zone->label();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function requestMunicipality(): PermitRequestMunicipality
    {
        return $this->requestMunicipality;
    }

    public function zone(): EventZone
    {
        return $this->zone;
    }

    public function zoneLabel(): string
    {
        return $this->zoneLabel;
    }
}
