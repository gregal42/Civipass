<?php

declare(strict_types=1);

namespace App\Rights\Domain;

use App\Request\Domain\PermitRequestMunicipality;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lien entre une demande de droits et un dossier COMMUNAL de la même commune (LOT 05.6.6). Supprimé avec le dossier à la purge (clé `RESTRICT`, règle 8) : la ligne de
 * la demande subsiste, sans donnée personnelle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rights_request_dossier')]
#[ORM\UniqueConstraint(name: 'uniq_rights_dossier', columns: ['rights_request_id', 'request_municipality_id'])]
#[ORM\Index(name: 'idx_rights_dossier_rm', columns: ['request_municipality_id'])]
class RightsRequestDossier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: RightsRequest::class)]
        #[ORM\JoinColumn(name: 'rights_request_id', nullable: false, onDelete: 'RESTRICT')]
        private RightsRequest $rightsRequest,
        #[ORM\ManyToOne(targetEntity: PermitRequestMunicipality::class)]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $dossier,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function rightsRequest(): RightsRequest
    {
        return $this->rightsRequest;
    }

    public function dossier(): PermitRequestMunicipality
    {
        return $this->dossier;
    }
}
