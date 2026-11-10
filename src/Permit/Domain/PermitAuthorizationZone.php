<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventZone;
use App\Shared\Domain\DomainRuleViolation;
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

    public function removedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function isActive(): bool
    {
        return null === $this->removedAt;
    }

    /**
     * Retrait LOGIQUE (LOT 05.5.3) : la ligne n'est jamais supprimée ; l'historique vit dans les révisions.
     *
     * @internal appelé par PermitAuthorization::retireZone()
     */
    public function retire(\DateTimeImmutable $at): void
    {
        if (null !== $this->removedAt) {
            throw new DomainRuleViolation('Cette zone est déjà retirée de l\'autorisation.');
        }
        $this->removedAt = $at;
    }

    /**
     * Réintégration d'une zone retirée : efface l'état « retiré » et recopie le libellé courant (l'historique des retraits est conservé par les révisions).
     *
     * @internal appelé par PermitAuthorization::reinstateZone()
     */
    public function reinstate(string $currentLabel): void
    {
        if (null === $this->removedAt) {
            throw new DomainRuleViolation('Cette zone n\'est pas retirée : il n\'y a rien à réintégrer.');
        }
        $this->removedAt = null;
        $this->zoneLabel = $currentLabel;
    }

    /**
     * Correction de libellé : ne se fait QUE par synchronisation avec le libellé courant de la même zone (jamais un texte libre).
     *
     * @internal appelé par PermitAuthorization::relabelZone()
     */
    public function synchronizeLabel(string $currentLabel): void
    {
        $this->zoneLabel = $currentLabel;
    }
}
