<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\PlateFormat;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Plaque autorisée, COPIÉE de la demande à la première émission (DT-042, DM-116) : partagée au niveau du laissez-passer (elle
 * concerne le contrôle de toutes les communes). Distincte de la plaque déclarée, que le laissez-passer ne modifie jamais.
 * Retrait logique (`removed_at`, LOT 05.5) : jamais de suppression physique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_vehicle')]
#[ORM\UniqueConstraint(name: 'uniq_permit_vehicle_registration', columns: ['permit_id', 'registration'])]
class PermitVehicle
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 12, options: ['collation' => 'utf8mb4_bin'])]
    private string $registration;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: PlateFormat::class)]
    private PlateFormat $format;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    /**
     * @internal construit par Permit
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Permit::class, inversedBy: 'vehicles', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'permit_id', nullable: false, onDelete: 'RESTRICT')]
        private Permit $permit,
        string $registration,
        PlateFormat $format,
        int $position,
    ) {
        $this->id = Uuid::v7();
        $this->registration = $registration;
        $this->format = $format;
        $this->position = $position;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function permit(): Permit
    {
        return $this->permit;
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

    public function removedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function isActive(): bool
    {
        return null === $this->removedAt;
    }

    /**
     * Retrait LOGIQUE (LOT 05.5.4) : la ligne n'est jamais supprimée ; l'historique vit dans les révisions.
     *
     * @internal appelé par PermitVehicleService
     */
    public function retire(\DateTimeImmutable $at): void
    {
        if (null !== $this->removedAt) {
            throw new DomainRuleViolation('Cette plaque est déjà retirée.');
        }
        $this->removedAt = $at;
    }

    /**
     * Réactivation d'une plaque retirée : la ligne est réutilisée (unicité `(laissez-passer, plaque)`), ses révisions passées restent.
     *
     * @internal appelé par PermitVehicleService
     */
    public function reinstate(): void
    {
        if (null === $this->removedAt) {
            throw new DomainRuleViolation('Cette plaque n\'est pas retirée : il n\'y a rien à réactiver.');
        }
        $this->removedAt = null;
    }
}
