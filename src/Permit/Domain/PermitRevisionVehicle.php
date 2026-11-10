<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\PlateFormat;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Plaque concernée par une révision (DM-119 §10) : table PRÉPARÉE au 05.5.1 avec le reste du schéma des révisions, utilisée par le LOT 05.5.4
 * (plaques). `registration` est la seule donnée personnelle des révisions et la seule anonymisable (valeur nulle après la durée de conservation) ;
 * jamais écrite dans l'audit (DT-037). Aucun producteur avant le 05.5.4.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_revision_vehicle')]
#[ORM\Index(name: 'idx_permit_revision_vehicle_revision', columns: ['revision_id'])]
class PermitRevisionVehicle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRevision::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRevision $revision,
        #[ORM\ManyToOne(targetEntity: PermitVehicle::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'vehicle_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitVehicle $vehicle,
        #[ORM\Column(type: Types::STRING, length: 12, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
        private ?string $registration,
        #[ORM\Column(type: Types::STRING, length: 3, enumType: PlateFormat::class)]
        private PlateFormat $format,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
        #[ORM\Column(type: Types::STRING, length: 12)]
        private string $action,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function revision(): PermitRevision
    {
        return $this->revision;
    }

    public function vehicle(): PermitVehicle
    {
        return $this->vehicle;
    }

    public function registration(): ?string
    {
        return $this->registration;
    }

    public function format(): PlateFormat
    {
        return $this->format;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function action(): string
    {
        return $this->action;
    }
}
