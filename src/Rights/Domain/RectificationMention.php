<?php

declare(strict_types=1);

namespace App\Rights\Domain;

use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Mention de rectification d'un dossier archivé (LOT 05.6.6, M9) : AJOUTÉE à côté de la valeur d'origine, jamais en remplacement (les demandes et décisions historiques ne
 * sont pas modifiées). Propre au dossier de la commune ; son texte peut contenir des données personnelles : il est supprimé avec le dossier à la purge.
 */
#[ORM\Entity]
#[ORM\Table(name: 'archive_rectification_mention')]
#[ORM\Index(name: 'idx_rectification_rm', columns: ['request_municipality_id'])]
class RectificationMention
{
    public const MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequestMunicipality::class)]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $dossier,
        #[ORM\ManyToOne(targetEntity: RightsRequest::class)]
        #[ORM\JoinColumn(name: 'rights_request_id', nullable: false, onDelete: 'RESTRICT')]
        private RightsRequest $rightsRequest,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $fields,
        #[ORM\Column(type: Types::STRING, length: self::MAX_LENGTH)]
        private string $mention,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $createdBy,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function dossier(): PermitRequestMunicipality
    {
        return $this->dossier;
    }

    public function fields(): string
    {
        return $this->fields;
    }

    public function mention(): string
    {
        return $this->mention;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): User
    {
        return $this->createdBy;
    }

    public function rightsRequest(): RightsRequest
    {
        return $this->rightsRequest;
    }
}
