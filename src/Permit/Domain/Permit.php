<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Event;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PlateFormat;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Laissez-passer : UN par demande (`UNIQUE(request_id)`), créé à la première approbation d'une commune, jamais recréé. Les approbations
 * suivantes lui ajoutent une autorisation communale ; son identité, sa référence `LP-` et son jeton QR ne changent pas (DM-112, DM-113).
 * Les plaques sont copiées de la demande à la première émission et partagées au niveau du laissez-passer. L'identité du bénéficiaire n'est
 * JAMAIS copiée (DM-111) et aucun état de validité n'est persisté : il est calculé (PermitRightsEvaluator).
 */
#[ORM\Entity]
#[ORM\Table(name: 'permit_permit')]
#[ORM\UniqueConstraint(name: 'uniq_permit_request', columns: ['request_id'])]
#[ORM\UniqueConstraint(name: 'uniq_permit_reference', columns: ['reference'])]
class Permit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['collation' => 'utf8mb4_bin'])]
    private string $reference;

    #[ORM\ManyToOne(targetEntity: PermitRequest::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'RESTRICT')]
    private PermitRequest $request;

    #[ORM\ManyToOne(targetEntity: Event::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: PermitRequestDecision::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'first_decision_id', nullable: false, onDelete: 'RESTRICT')]
    private PermitRequestDecision $firstDecision;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, VerificationToken> */
    #[ORM\OneToMany(targetEntity: VerificationToken::class, mappedBy: 'permit', cascade: ['persist'])]
    private Collection $tokens;

    /** @var Collection<int, PermitAuthorization> */
    #[ORM\OneToMany(targetEntity: PermitAuthorization::class, mappedBy: 'permit', cascade: ['persist'])]
    private Collection $authorizations;

    /** @var Collection<int, PermitVehicle> */
    #[ORM\OneToMany(targetEntity: PermitVehicle::class, mappedBy: 'permit', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $vehicles;

    private function __construct(PermitRequest $request, PermitRequestDecision $firstDecision, PermitReference $reference, string $verificationToken, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->reference = $reference->value;
        $this->request = $request;
        $this->event = $request->event();
        $this->firstDecision = $firstDecision;
        $this->createdAt = $now;
        $this->tokens = new ArrayCollection();
        $this->authorizations = new ArrayCollection();
        $this->vehicles = new ArrayCollection();
        $this->tokens->add(new VerificationToken($this, $verificationToken, $now));
        foreach ($request->vehicles() as $declared) {
            $this->vehicles->add(new PermitVehicle($this, $declared->registration(), $declared->format(), $declared->position()));
        }
    }

    /**
     * Émet le laissez-passer de la demande, à la première approbation. L'autorisation de cette première commune s'ajoute ensuite par
     * `authorize()`. Les plaques déclarées sont copiées ici, UNE fois.
     *
     * @throws DomainRuleViolation la décision n'est pas une approbation de cette demande
     */
    public static function issue(PermitRequest $request, PermitRequestDecision $firstDecision, PermitReference $reference, \DateTimeImmutable $now): self
    {
        if (!$firstDecision->requestMunicipality()->request()->id()->equals($request->id())) {
            throw new DomainRuleViolation('La décision n\'appartient pas à cette demande.');
        }

        return new self($request, $firstDecision, $reference, VerificationToken::generate(), $now);
    }

    /**
     * Ajoute l'autorisation de la commune qui vient d'approuver (fenêtre et zones copiées de la décision). Rejouer la même décision ne
     * crée rien : l'autorisation existante est renvoyée.
     *
     * @throws DomainRuleViolation la décision n'est pas une approbation de cette demande
     */
    public function authorize(PermitRequestMunicipality $part, PermitRequestDecision $decision): PermitAuthorization
    {
        if (!$part->request()->id()->equals($this->request->id())) {
            throw new DomainRuleViolation('La part n\'appartient pas à la demande de ce laissez-passer.');
        }
        $existing = $this->authorizationForDecision($decision->id());
        if (null !== $existing) {
            return $existing;
        }
        $authorization = new PermitAuthorization($this, $part, $decision);
        $this->authorizations->add($authorization);

        return $authorization;
    }

    public function authorizationForDecision(Uuid $decisionId): ?PermitAuthorization
    {
        foreach ($this->authorizations as $authorization) {
            if ($authorization->decision()->id()->equals($decisionId)) {
                return $authorization;
            }
        }

        return null;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function reference(): PermitReference
    {
        return PermitReference::fromString($this->reference);
    }

    public function request(): PermitRequest
    {
        return $this->request;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function firstDecision(): PermitRequestDecision
    {
        return $this->firstDecision;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Jeton de vérification actif (un seul). */
    public function activeToken(): VerificationToken
    {
        foreach ($this->tokens as $token) {
            if ($token->isActive()) {
                return $token;
            }
        }

        throw new \LogicException('Un laissez-passer possède toujours un jeton de vérification actif.');
    }

    /**
     * Ajoute le NOUVEAU jeton actif d'un remplacement du QR (LOT 05.5.5) ; l'ancien doit avoir été retiré et écrit AVANT (un seul jeton actif : index unique).
     *
     * @internal appelé par PermitTokenReplacementService
     */
    public function addToken(string $token, \DateTimeImmutable $now): VerificationToken
    {
        $new = new VerificationToken($this, $token, $now);
        $this->tokens->add($new);

        return $new;
    }

    /**
     * @return list<PermitAuthorization>
     */
    public function authorizations(): array
    {
        return array_values($this->authorizations->toArray());
    }

    /**
     * TOUTES les plaques (actives et retirées), dans l'ordre de saisie : lecture seule, sans verrou (aperçu). Les écritures relisent les plaques sous verrou.
     *
     * @return list<PermitVehicle>
     */
    public function vehicles(): array
    {
        return array_values($this->vehicles->toArray());
    }

    /**
     * Plaques autorisées non retirées, dans l'ordre de saisie.
     *
     * @return list<PermitVehicle>
     */
    public function activeVehicles(): array
    {
        return array_values(array_filter($this->vehicles->toArray(), static fn (PermitVehicle $v): bool => $v->isActive()));
    }

    /**
     * Ajoute une plaque NOUVELLE (LOT 05.5.4). Les règles (mode, plafond, doublon) sont celles de `VehicleChangePlanner`, appliquées sous verrou par le service.
     *
     * @internal appelé par PermitVehicleService
     */
    public function addVehicle(string $registration, PlateFormat $format, int $position): PermitVehicle
    {
        $vehicle = new PermitVehicle($this, $registration, $format, $position);
        $this->vehicles->add($vehicle);

        return $vehicle;
    }
}
