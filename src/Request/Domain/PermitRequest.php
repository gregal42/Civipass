<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Event;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\PhoneNumber;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Dossier de demande de laissez-passer : un seul événement, une part par commune concernée (DM-101), figé après dépôt
 * (aucune colonne de modification ; la correction d'identité par un agent relève du LOT 04).
 *
 * Invariants portés ici (docs/lot-03-schema.md §6) : au moins une commune et une zone, sans doublon ; chaque
 * participation appartient à l'événement et chaque zone à sa participation ; zones proposables au dépôt selon
 * `EventZone::mayOpenRights()` (DM-104) ; au moins un contact ; catégorie et motif actifs, précision si le motif
 * l'exige ; déplacement exclusif et plaques (DM-103) ; au plus 5 justificatifs ; attestation de confidentialité ;
 * créateur et commune de saisie cohérents avec l'origine (DM-099).
 *
 * Hors du domaine (service de dépôt, incrément 2) : droit de saisie de l'agent, état de l'événement relu sous verrou,
 * contrôle des créneaux d'une zone en périodes spécifiques, vérification des fichiers réels, idempotence.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request')]
#[ORM\UniqueConstraint(name: 'uniq_request_permit_request_reference', columns: ['public_reference'])]
#[ORM\Index(name: 'idx_request_permit_request_event_submitted', columns: ['event_id', 'submitted_at'])]
class PermitRequest
{
    public const MAX_VEHICLES = 10;
    public const NAME_MAX_LENGTH = 120;
    public const ADDRESS_MAX_LENGTH = 255;
    public const DETAIL_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['collation' => 'utf8mb4_bin'])]
    private string $publicReference;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: RequestOrigin::class)]
    private RequestOrigin $origin;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: self::ADDRESS_MAX_LENGTH, nullable: true)]
    private ?string $address;

    #[ORM\Column(type: Types::STRING, length: EmailAddress::MAX_LENGTH, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $phone;

    #[ORM\ManyToOne(targetEntity: BeneficiaryCategory::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'RESTRICT')]
    private BeneficiaryCategory $category;

    #[ORM\Column(type: Types::STRING, length: BeneficiaryCategory::LABEL_MAX_LENGTH)]
    private string $categoryLabel;

    #[ORM\ManyToOne(targetEntity: RequestMotif::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'motif_id', nullable: false, onDelete: 'RESTRICT')]
    private RequestMotif $motif;

    #[ORM\Column(type: Types::STRING, length: RequestMotif::LABEL_MAX_LENGTH)]
    private string $motifLabel;

    #[ORM\Column(type: Types::STRING, length: self::DETAIL_MAX_LENGTH, nullable: true)]
    private ?string $motifDetail;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: TravelMode::class)]
    private TravelMode $travelMode;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $privacyAttestedAt;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $privacyNoticeVersion;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'entered_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $enteredByUser;

    #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'entered_by_municipality_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Municipality $enteredByMunicipality;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    /** @var Collection<int, PermitRequestMunicipality> */
    #[ORM\OneToMany(targetEntity: PermitRequestMunicipality::class, mappedBy: 'request', cascade: ['persist'])]
    private Collection $municipalities;

    /** @var Collection<int, PermitRequestVehicle> */
    #[ORM\OneToMany(targetEntity: PermitRequestVehicle::class, mappedBy: 'request', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $vehicles;

    /** @var Collection<int, PermitRequestAttachment> */
    #[ORM\OneToMany(targetEntity: PermitRequestAttachment::class, mappedBy: 'request', cascade: ['persist'])]
    private Collection $attachments;

    /**
     * @param list<string> $plates Plaques saisies, en clair (normalisées ici)
     */
    private function __construct(
        RequestReference $reference,
        #[ORM\ManyToOne(targetEntity: Event::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
        private Event $event,
        RequestOrigin $origin,
        string $lastName,
        string $firstName,
        ?string $address,
        ?EmailAddress $email,
        ?PhoneNumber $phone,
        BeneficiaryCategory $category,
        RequestMotif $motif,
        ?string $motifDetail,
        TravelMode $travelMode,
        array $plates,
        bool $privacyAttested,
        ?string $privacyNoticeVersion,
        ?User $enteredByUser,
        ?Municipality $enteredByMunicipality,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->publicReference = $reference->value;
        $this->origin = $origin;
        $this->lastName = self::requiredName($lastName, 'Le nom');
        $this->firstName = self::requiredName($firstName, 'Le prénom');
        $this->address = self::optionalText($address, self::ADDRESS_MAX_LENGTH, 'L\'adresse');
        if (null === $email && null === $phone) {
            throw new DomainRuleViolation('Renseignez au moins un moyen de contact : adresse e-mail ou téléphone.');
        }
        $this->email = $email?->value;
        $this->phone = $phone?->e164;

        if (!$category->isActive()) {
            throw new DomainRuleViolation('Cette catégorie de bénéficiaire n\'est plus proposée.');
        }
        if (!$motif->isActive()) {
            throw new DomainRuleViolation('Ce motif n\'est plus proposé.');
        }
        $motifDetail = self::optionalText($motifDetail, self::DETAIL_MAX_LENGTH, 'La précision du motif');
        if ($motif->needsDetail() && null === $motifDetail) {
            throw new DomainRuleViolation('La précision est obligatoire pour ce motif.');
        }
        $this->category = $category;
        $this->categoryLabel = $category->label();
        $this->motif = $motif;
        $this->motifLabel = $motif->label();
        $this->motifDetail = $motifDetail;
        $this->travelMode = $travelMode;

        if (!$privacyAttested) {
            throw new DomainRuleViolation(RequestOrigin::Agent === $origin ? 'Attestez que le bénéficiaire a été informé de la politique de confidentialité.' : 'Confirmez avoir pris connaissance de la politique de confidentialité.');
        }
        $this->privacyAttestedAt = $now;
        $this->privacyNoticeVersion = self::optionalText($privacyNoticeVersion, 40, 'La version du texte de confidentialité');

        if (RequestOrigin::Agent === $origin && (null === $enteredByUser || null === $enteredByMunicipality)) {
            throw new DomainRuleViolation('Une demande saisie par un agent indique son créateur et sa commune de saisie.');
        }
        if (RequestOrigin::Public === $origin && (null !== $enteredByUser || null !== $enteredByMunicipality)) {
            throw new DomainRuleViolation('Un dépôt public n\'a ni agent créateur ni commune de saisie.');
        }
        $this->enteredByUser = $enteredByUser;
        $this->enteredByMunicipality = $enteredByMunicipality;
        $this->submittedAt = $now;
        $this->municipalities = new ArrayCollection();
        $this->vehicles = new ArrayCollection();
        $this->attachments = new ArrayCollection();

        $this->addVehicles($plates);
    }

    /**
     * Dépose un dossier. Les justificatifs s'ajoutent ensuite, dans la même transaction, par `attach()`.
     *
     * @param list<RequestedTerritory> $territories
     * @param list<string>             $plates      plaques saisies (vide en déplacement à pied ou à vélo)
     */
    public static function submit(
        RequestReference $reference,
        Event $event,
        RequestOrigin $origin,
        string $lastName,
        string $firstName,
        ?string $address,
        ?EmailAddress $email,
        ?PhoneNumber $phone,
        BeneficiaryCategory $category,
        RequestMotif $motif,
        ?string $motifDetail,
        TravelMode $travelMode,
        array $plates,
        array $territories,
        bool $privacyAttested,
        ?string $privacyNoticeVersion,
        ?User $enteredByUser,
        ?Municipality $enteredByMunicipality,
        \DateTimeImmutable $now,
    ): self {
        $request = new self(
            $reference,
            $event,
            $origin,
            $lastName,
            $firstName,
            $address,
            $email,
            $phone,
            $category,
            $motif,
            $motifDetail,
            $travelMode,
            $plates,
            $privacyAttested,
            $privacyNoticeVersion,
            $enteredByUser,
            $enteredByMunicipality,
            $now,
        );
        $request->addTerritories($territories, $now);

        return $request;
    }

    public function attach(string $originalName, string $mimeType, int $sizeBytes, string $storageKey, \DateTimeImmutable $now): PermitRequestAttachment
    {
        if ($this->attachments->count() >= PermitRequestAttachment::MAX_PER_REQUEST) {
            throw new DomainRuleViolation(sprintf('Une demande comporte au plus %d justificatifs.', PermitRequestAttachment::MAX_PER_REQUEST));
        }
        $attachment = new PermitRequestAttachment($this, $originalName, $mimeType, $sizeBytes, $storageKey, $now);
        $this->attachments->add($attachment);

        return $attachment;
    }

    /**
     * @param list<RequestedTerritory> $territories
     */
    private function addTerritories(array $territories, \DateTimeImmutable $now): void
    {
        if ([] === $territories) {
            throw new DomainRuleViolation('Choisissez au moins une commune et une zone.');
        }
        $participations = [];
        $zones = [];
        foreach ($territories as $territory) {
            $participation = $territory->participation;
            if (!$participation->event()->id()->equals($this->event->id())) {
                throw new DomainRuleViolation('Une commune demandée ne participe pas à cet événement.');
            }
            $participationId = $participation->id()->toRfc4122();
            if (isset($participations[$participationId])) {
                throw new DomainRuleViolation('Une commune ne peut être demandée qu\'une seule fois.');
            }
            $participations[$participationId] = true;
            foreach ($territory->zones as $zone) {
                if (!$zone->participation()->id()->equals($participation->id())) {
                    throw new DomainRuleViolation('Une zone demandée n\'appartient pas à la commune concernée.');
                }
                $zoneId = $zone->id()->toRfc4122();
                if (isset($zones[$zoneId])) {
                    throw new DomainRuleViolation('Une zone ne peut être demandée qu\'une seule fois.');
                }
                $zones[$zoneId] = true;
                if (!$zone->mayOpenRights()) {
                    throw new DomainRuleViolation(sprintf('La zone « %s » n\'est pas proposée aux demandes.', $zone->label()));
                }
            }
            $this->municipalities->add(new PermitRequestMunicipality($this, $participation, $territory->zones, $now));
        }
    }

    /**
     * @param list<string> $plates
     */
    private function addVehicles(array $plates): void
    {
        if (TravelMode::FootBike === $this->travelMode) {
            if ([] !== $plates) {
                throw new DomainRuleViolation('Aucune plaque ne se déclare pour un déplacement à pied ou à vélo.');
            }

            return;
        }
        if ([] === $plates || \count($plates) > self::MAX_VEHICLES) {
            throw new DomainRuleViolation(sprintf('Un déplacement en véhicule comporte de 1 à %d plaques.', self::MAX_VEHICLES));
        }
        $seen = [];
        foreach ($plates as $position => $raw) {
            $plate = LicensePlate::fromInput($raw);
            if (isset($seen[$plate->value])) {
                throw new DomainRuleViolation(sprintf('La plaque %s est saisie plusieurs fois.', $plate->value));
            }
            $seen[$plate->value] = true;
            $this->vehicles->add(new PermitRequestVehicle($this, $plate, $position));
        }
    }

    private static function requiredName(string $value, string $subject): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > self::NAME_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('%s est obligatoire (%d caractères maximum).', $subject, self::NAME_MAX_LENGTH));
        }

        return $value;
    }

    private static function optionalText(?string $value, int $max, string $subject): ?string
    {
        $value = null === $value ? '' : trim($value);
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new DomainRuleViolation(sprintf('%s ne peut pas dépasser %d caractères.', $subject, $max));
        }

        return $value;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function reference(): RequestReference
    {
        return RequestReference::fromString($this->publicReference);
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function origin(): RequestOrigin
    {
        return $this->origin;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function category(): BeneficiaryCategory
    {
        return $this->category;
    }

    public function categoryLabel(): string
    {
        return $this->categoryLabel;
    }

    public function motif(): RequestMotif
    {
        return $this->motif;
    }

    public function motifLabel(): string
    {
        return $this->motifLabel;
    }

    public function motifDetail(): ?string
    {
        return $this->motifDetail;
    }

    public function travelMode(): TravelMode
    {
        return $this->travelMode;
    }

    public function privacyAttestedAt(): \DateTimeImmutable
    {
        return $this->privacyAttestedAt;
    }

    public function privacyNoticeVersion(): ?string
    {
        return $this->privacyNoticeVersion;
    }

    public function enteredByUser(): ?User
    {
        return $this->enteredByUser;
    }

    public function enteredByMunicipality(): ?Municipality
    {
        return $this->enteredByMunicipality;
    }

    public function submittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * @return list<PermitRequestMunicipality>
     */
    public function municipalities(): array
    {
        return array_values($this->municipalities->toArray());
    }

    /**
     * @return list<PermitRequestVehicle>
     */
    public function vehicles(): array
    {
        return array_values($this->vehicles->toArray());
    }

    /**
     * @return list<PermitRequestAttachment>
     */
    public function attachments(): array
    {
        return array_values($this->attachments->toArray());
    }
}
