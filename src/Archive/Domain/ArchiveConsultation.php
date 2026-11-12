<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'UNE consultation d'archive (LOT 05.6.3) : consultant, date UTC, dossier communal, action, motif (code et commentaire court), pièce éventuelle. Écrit
 * AVANT l'envoi du contenu ; jamais modifié ni supprimé par l'application (purge au 05.6.4, avec le dossier). La pièce est référencée SANS clé étrangère.
 */
#[ORM\Entity]
#[ORM\Table(name: 'archive_consultation')]
#[ORM\Index(name: 'idx_archive_consultation_dossier', columns: ['request_municipality_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_archive_consultation_user', columns: ['user_id', 'occurred_at'])]
class ArchiveConsultation
{
    public const MAX_COMMENT = 200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequestMunicipality::class)]
        #[ORM\JoinColumn(name: 'request_municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequestMunicipality $dossier,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\Column(type: Types::STRING, length: 24, enumType: ConsultationAction::class)]
        private ConsultationAction $action,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: ConsultationReason::class)]
        private ConsultationReason $reason,
        #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
        private ?string $reasonComment,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $attachmentId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
    ) {
        if (ConsultationReason::Other === $reason && (null === $reasonComment || '' === trim($reasonComment))) {
            throw new DomainRuleViolation('Un commentaire est obligatoire pour le motif « autre ».');
        }
        if (null !== $reasonComment && mb_strlen($reasonComment) > self::MAX_COMMENT) {
            throw new DomainRuleViolation('Le commentaire du motif est limité à 200 caractères.');
        }
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

    public function user(): User
    {
        return $this->user;
    }

    public function action(): ConsultationAction
    {
        return $this->action;
    }

    public function reason(): ConsultationReason
    {
        return $this->reason;
    }

    public function reasonComment(): ?string
    {
        return $this->reasonComment;
    }

    public function attachmentId(): ?Uuid
    {
        return $this->attachmentId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
