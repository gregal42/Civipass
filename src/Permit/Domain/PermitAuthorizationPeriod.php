<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\DurationMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Période CLOSE d'une autorisation (LOT 05.5.2, DM-119 §4.2, modélisation B2). Les colonnes de l'autorisation décrivent la période COURANTE ; cette
 * table conserve les périodes antérieures, arrêtées à l'occasion d'une reprise après expiration. Immuable : constructeur fermé à l'application
 * (créée par `PermitAuthorization::resumeAt`), aucun mutateur, jamais supprimée. `valid_until` n'est jamais nul (une période close a une fin) ;
 * début inclus, fin exclue. L'interruption entre deux périodes ne donne aucun droit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_authorization_period')]
#[ORM\UniqueConstraint(name: 'uniq_permit_authorization_period_position', columns: ['authorization_id', 'position'])]
#[ORM\Index(name: 'idx_permit_authorization_period_revision', columns: ['closed_by_revision_id'])]
class PermitAuthorizationPeriod
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @internal construit par PermitAuthorization::resumeAt() */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitAuthorization::class, inversedBy: 'closedPeriods', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'authorization_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitAuthorization $authorization,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        private int $position,
        #[ORM\Column(type: Types::STRING, length: 20, enumType: DurationMode::class)]
        private DurationMode $durationMode,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $validFrom,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $validUntil,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $closedAt,
        #[ORM\ManyToOne(targetEntity: PermitRevision::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'closed_by_revision_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRevision $closedByRevision,
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

    public function position(): int
    {
        return $this->position;
    }

    public function durationMode(): DurationMode
    {
        return $this->durationMode;
    }

    public function validFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function closedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function closedByRevision(): PermitRevision
    {
        return $this->closedByRevision;
    }

    /** Fenêtre de cette période, pour le calcul des droits. */
    public function window(): \App\Request\Domain\GrantWindow
    {
        return new \App\Request\Domain\GrantWindow($this->durationMode, $this->validFrom, $this->validUntil);
    }
}
