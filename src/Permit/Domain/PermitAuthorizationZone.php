<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Zone autorisée par une autorisation communale : copie, à l'émission, des zones de la part approuvée (le libellé est copié, la zone
 * peut être renommée ensuite). Retrait logique (`removed_at`, LOT 05.5). Les périodes d'accès ne sont jamais copiées : elles se calculent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_authorization_zone')]
#[ORM\UniqueConstraint(name: 'uniq_permit_authorization_zone', columns: ['authorization_id', 'zone_id'])]
class PermitAuthorizationZone
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    // @phpstan-ignore property.unusedType (assigné par les révisions du LOT 05.5 ; hydraté par Doctrine)
    private ?\DateTimeImmutable $removedAt = null;

    /**
     * @internal construit par PermitAuthorization
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitAuthorization::class, inversedBy: 'zones', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'authorization_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitAuthorization $authorization,
        #[ORM\ManyToOne(targetEntity: EventZone::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'zone_id', nullable: false, onDelete: 'RESTRICT')]
        private EventZone $zone,
        #[ORM\Column(type: Types::STRING, length: EventZone::LABEL_MAX_LENGTH)]
        private string $zoneLabel,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function authorization(): PermitAuthorization
    {
        return $this->authorization;
    }

    public function zone(): EventZone
    {
        return $this->zone;
    }

    public function zoneLabel(): string
    {
        return $this->zoneLabel;
    }

    public function isActive(): bool
    {
        return null === $this->removedAt;
    }
}
