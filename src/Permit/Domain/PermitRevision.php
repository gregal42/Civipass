<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Révision IMMUABLE des droits d'un laissez-passer (LOT 05.5.1, DM-119 §10) : qui, quand, quoi, pourquoi, état avant et après. Aucun mutateur :
 * une révision n'est jamais modifiée ni supprimée par l'application. La SEULE exception prévue (non livrée au 05.5) est l'anonymisation du
 * commentaire par un service dédié de conservation (`anonymized_at`), qui ne supprime aucune ligne.
 *
 * `beforeState` et `afterState` sont des structures sans donnée personnelle (fenêtre, identifiants, libellés de zone) ; jamais un jeton, une
 * plaque, un nom ni une adresse. Le commentaire est un texte libre court (200 caractères), visible de la seule commune qui l'a saisi, jamais public.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_revision')]
#[ORM\Index(name: 'idx_permit_revision_permit', columns: ['permit_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_permit_revision_authorization', columns: ['authorization_id', 'occurred_at'])]
class PermitRevision
{
    public const COMMENT_MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    // @phpstan-ignore property.unusedType (renseigné par le futur service d'anonymisation ; hydraté par Doctrine)
    private ?\DateTimeImmutable $anonymizedAt = null;

    /**
     * @param array<string, scalar|list<scalar>|null> $beforeState
     * @param array<string, scalar|list<scalar>|null> $afterState
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Permit::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'permit_id', nullable: false, onDelete: 'RESTRICT')]
        private Permit $permit,
        #[ORM\ManyToOne(targetEntity: PermitAuthorization::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'authorization_id', nullable: true, onDelete: 'RESTRICT')]
        private ?PermitAuthorization $authorization,
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'actor_user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $actor,
        #[ORM\Column(type: Types::STRING, length: 32, enumType: RevisionType::class)]
        private RevisionType $type,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $batchId,
        #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
        private ?string $reasonCode,
        #[ORM\Column(type: Types::STRING, length: self::COMMENT_MAX_LENGTH, nullable: true)]
        private ?string $reasonText,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $beforeState,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $afterState,
    ) {
        $this->id = Uuid::v7();
    }

    /**
     * Révocation d'une autorisation communale. Le commentaire est déjà validé (200 caractères, obligatoire pour « Autre ») par le service.
     *
     * @param array<string, scalar|list<scalar>|null> $before
     * @param array<string, scalar|list<scalar>|null> $after
     */
    public static function revocation(PermitAuthorization $authorization, User $actor, RevocationReason $reason, ?string $comment, \DateTimeImmutable $at, array $before, array $after): self
    {
        return new self($authorization->permit(), $authorization, $authorization->municipality(), $actor, RevisionType::AuthorizationRevoked, $at, Uuid::v7(), $reason->value, $comment, $before, $after);
    }

    /**
     * Révision de la période d'une autorisation (prolongation, reprise, raccourcissement : LOT 05.5.2). Motif et commentaire déjà validés.
     *
     * @param array<string, scalar|list<scalar>|null> $before
     * @param array<string, scalar|list<scalar>|null> $after
     */
    public static function period(RevisionType $type, PermitAuthorization $authorization, User $actor, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at, array $before, array $after): self
    {
        return new self($authorization->permit(), $authorization, $authorization->municipality(), $actor, $type, $at, Uuid::v7(), $reasonCode, $comment, $before, $after);
    }

    /**
     * Révision d'UNE zone d'une autorisation (ajout, retrait, réintégration, correction de libellé : LOT 05.5.3). Les révisions d'une même opération partagent le
     * `batchId`. États structurés (identifiant et libellé de zone, zones rattachées) : aucune donnée personnelle.
     *
     * @param array<string, scalar|list<scalar>|null> $before
     * @param array<string, scalar|list<scalar>|null> $after
     */
    public static function zone(RevisionType $type, PermitAuthorization $authorization, User $actor, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at, Uuid $batchId, array $before, array $after): self
    {
        return new self($authorization->permit(), $authorization, $authorization->municipality(), $actor, $type, $at, $batchId, $reasonCode, $comment, $before, $after);
    }

    /**
     * Révision d'UNE plaque (ajout, retrait, réactivation, remplacement : LOT 05.5.4). Les plaques appartiennent au LAISSEZ-PASSER, pas à une autorisation : la révision
     * n'a pas d'autorisation (DM-119 §10) et porte la commune qui agit. Les états ne contiennent AUCUNE plaque (compteurs seulement) ; la plaque vit dans
     * `permit_revision_vehicle`. Les révisions d'une même opération partagent le `batchId`.
     *
     * @param array<string, scalar|list<scalar>|null> $before
     * @param array<string, scalar|list<scalar>|null> $after
     */
    public static function vehicle(RevisionType $type, Permit $permit, Municipality $municipality, User $actor, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at, Uuid $batchId, array $before, array $after): self
    {
        return new self($permit, null, $municipality, $actor, $type, $at, $batchId, $reasonCode, $comment, $before, $after);
    }

    /**
     * Révision du REMPLACEMENT du QR code (LOT 05.5.5). Niveau laissez-passer : pas d'autorisation, commune agissante renseignée. Les états ne contiennent JAMAIS un jeton :
     * numéro d'ordre du remplacement, identifiants des lignes de jeton (UUID internes), indicateurs « rapproché » et « inhabituel ». Le `batchId` est l'identifiant
     * d'opération du formulaire (idempotence).
     *
     * @param array<string, scalar|list<scalar>|null> $before
     * @param array<string, scalar|list<scalar>|null> $after
     */
    public static function token(Permit $permit, Municipality $municipality, User $actor, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at, Uuid $batchId, array $before, array $after): self
    {
        return new self($permit, null, $municipality, $actor, RevisionType::TokenReplaced, $at, $batchId, $reasonCode, $comment, $before, $after);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function permit(): Permit
    {
        return $this->permit;
    }

    public function authorization(): ?PermitAuthorization
    {
        return $this->authorization;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function actor(): User
    {
        return $this->actor;
    }

    public function type(): RevisionType
    {
        return $this->type;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function batchId(): Uuid
    {
        return $this->batchId;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function reasonText(): ?string
    {
        return $this->reasonText;
    }

    /** @return array<string, mixed>|null */
    public function beforeState(): ?array
    {
        return $this->beforeState;
    }

    /** @return array<string, mixed>|null */
    public function afterState(): ?array
    {
        return $this->afterState;
    }

    public function isAnonymized(): bool
    {
        return null !== $this->anonymizedAt;
    }
}
