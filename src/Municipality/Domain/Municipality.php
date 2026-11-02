<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Identity\Domain\EmailAddress;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'municipality')]
#[ORM\UniqueConstraint(name: 'uniq_municipality_insee_code', columns: ['insee_code'])]
class Municipality
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 5, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
    private string $inseeCode;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MunicipalityStatus::class)]
    private MunicipalityStatus $status = MunicipalityStatus::Active;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $addressLine = null;

    #[ORM\Column(type: Types::STRING, length: 5, nullable: true, options: ['fixed' => true])]
    private ?string $postalCode = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 150)]
        private string $name,
        InseeCode $inseeCode,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        self::assertName($name);
        $this->id = Uuid::v7();
        $this->inseeCode = $inseeCode->value;
        $this->updatedAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function inseeCode(): InseeCode
    {
        return InseeCode::fromString($this->inseeCode);
    }

    public function status(): MunicipalityStatus
    {
        return $this->status;
    }

    public function contactName(): ?string
    {
        return $this->contactName;
    }

    public function contactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function contactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function addressLine(): ?string
    {
        return $this->addressLine;
    }

    public function postalCode(): ?string
    {
        return $this->postalCode;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    /**
     * @return array{contactName: ?string, contactEmail: ?string, contactPhone: ?string, addressLine: ?string, postalCode: ?string, city: ?string}
     */
    public function contactSnapshot(): array
    {
        return [
            'contactName' => $this->contactName,
            'contactEmail' => $this->contactEmail,
            'contactPhone' => $this->contactPhone,
            'addressLine' => $this->addressLine,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
        ];
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        self::assertName($name);
        $this->name = $name;
        $this->updatedAt = $now;
    }

    /**
     * Champ audité : réservé à MunicipalityManager (vérifié par un test d'architecture).
     */
    public function changeStatus(MunicipalityStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function updateContact(
        ?string $contactName,
        ?EmailAddress $contactEmail,
        ?PhoneNumber $contactPhone,
        ?string $addressLine,
        ?string $postalCode,
        ?string $city,
        \DateTimeImmutable $now,
    ): void {
        $contactName = self::nullIfBlank($contactName);
        $addressLine = self::nullIfBlank($addressLine);
        $postalCode = self::nullIfBlank($postalCode);
        $city = self::nullIfBlank($city);

        if (null !== $contactName && mb_strlen($contactName) > 150) {
            throw new DomainRuleViolation('Nom du contact trop long (150 caractères maximum).');
        }
        if (null !== $addressLine && mb_strlen($addressLine) > 255) {
            throw new DomainRuleViolation('Adresse trop longue (255 caractères maximum).');
        }
        if (null !== $city && mb_strlen($city) > 100) {
            throw new DomainRuleViolation('Ville trop longue (100 caractères maximum).');
        }
        if (null !== $postalCode && 1 !== preg_match('/^\d{5}$/', $postalCode)) {
            throw new DomainRuleViolation('Code postal invalide : 5 chiffres attendus.');
        }
        if ((null === $postalCode) !== (null === $city)) {
            throw new DomainRuleViolation('Le code postal et la ville doivent être renseignés ensemble.');
        }

        $this->contactName = $contactName;
        $this->contactEmail = $contactEmail?->value;
        $this->contactPhone = $contactPhone?->e164;
        $this->addressLine = $addressLine;
        $this->postalCode = $postalCode;
        $this->city = $city;
        $this->updatedAt = $now;
    }

    private static function assertName(string $name): void
    {
        if ('' === trim($name) || mb_strlen($name) > 150) {
            throw new DomainRuleViolation('Le nom de la commune est obligatoire (150 caractères maximum).');
        }
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
