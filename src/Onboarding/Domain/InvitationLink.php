<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Event\Domain\EventParticipation;
use App\Identity\Domain\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Lien sécurisé d'une invitation intercommunale. Rattaché à UNE participation `INVITED` ; ne donne par lui-même AUCUN droit :
 * il ouvre une page d'accueil (informations déjà contenues dans l'e-mail) puis un parcours qui vérifie les droits réels
 * (EventAccessPolicy, appartenance communale). Conservé après usage (historique) : `consumedAt` marque l'usage, `revokedAt` le
 * remplacement par un lien plus récent. Seule l'empreinte du jeton est stockée.
 *
 * Durées (paramètres techniques, non décisions métier : l'invitation elle-même n'expire pas, DM-086 ; un lien expiré se
 * réémet par « Renvoyer l'invitation ») : lien de contact 14 jours, lien d'administrateur 3 jours.
 */
#[ORM\Entity]
#[ORM\Table(name: 'onboarding_invitation_link')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_link_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_invitation_link_participation', columns: ['participation_id', 'kind', 'created_at'])]
class InvitationLink
{
    public const CONTACT_LIFETIME = 'P14D';
    public const ADMINISTRATOR_LIFETIME = 'P3D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InvitationDeliveryStatus::class)]
    private InvitationDeliveryStatus $deliveryStatus = InvitationDeliveryStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryAttemptedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: EventParticipation::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'participation_id', nullable: false, onDelete: 'RESTRICT')]
        private EventParticipation $participation,
        #[ORM\Column(type: Types::STRING, length: 13, enumType: InvitationLinkKind::class)]
        private InvitationLinkKind $kind,
        #[ORM\Column(type: Types::STRING, length: 180)]
        private string $recipientEmail,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $tokenHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'RESTRICT')]
        private ?User $createdBy = null,
        #[ORM\ManyToOne(targetEntity: self::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'parent_link_id', nullable: true, onDelete: 'RESTRICT')]
        private ?self $parent = null,
    ) {
        $this->id = Uuid::v7();
        $this->expiresAt = $createdAt->add(new \DateInterval(InvitationLinkKind::Contact === $kind ? self::CONTACT_LIFETIME : self::ADMINISTRATOR_LIFETIME));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function participation(): EventParticipation
    {
        return $this->participation;
    }

    public function kind(): InvitationLinkKind
    {
        return $this->kind;
    }

    /** Adresse saisie : jamais affichée à un tiers, jamais écrite en clair dans l'audit ni les journaux. */
    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdBy(): ?User
    {
        return $this->createdBy;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function consumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function deliveryStatus(): InvitationDeliveryStatus
    {
        return $this->deliveryStatus;
    }

    public function deliveryAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->deliveryAttemptedAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** Utilisable : ni consommé, ni remplacé, ni expiré. */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && null === $this->revokedAt && !$this->isExpiredAt($now);
    }

    public function markDelivery(InvitationDeliveryStatus $status, \DateTimeImmutable $now): void
    {
        $this->deliveryStatus = $status;
        $this->deliveryAttemptedAt = $now;
    }

    /** Cohérence du modèle ; en production la consommation passe par une mise à jour SQL conditionnelle atomique. */
    public function markConsumed(\DateTimeImmutable $now): void
    {
        $this->consumedAt ??= $now;
    }

    public function markRevoked(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
