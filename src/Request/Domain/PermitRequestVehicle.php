<?php

declare(strict_types=1);

namespace App\Request\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Plaque déclarée dans le dossier (normalisée, SIV ou FNI). Valeur déclarée : ne valide jamais une autorisation passée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request_vehicle')]
#[ORM\UniqueConstraint(name: 'uniq_request_vehicle_request_registration', columns: ['request_id', 'registration'])]
class PermitRequestVehicle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 12, options: ['collation' => 'utf8mb4_bin'])]
    private string $registration;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: PlateFormat::class)]
    private PlateFormat $format;

    /**
     * @internal construit uniquement par PermitRequest
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequest::class, inversedBy: 'vehicles', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequest $request,
        LicensePlate $plate,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
    ) {
        $this->id = Uuid::v7();
        $this->registration = $plate->value;
        $this->format = $plate->format;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function request(): PermitRequest
    {
        return $this->request;
    }

    public function registration(): string
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
}
