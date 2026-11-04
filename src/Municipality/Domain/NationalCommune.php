<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Référentiel NATIONAL des communes (source officielle : COG INSEE via l'API Géo), stocké localement pour que la recherche ne
 * dépende pas de la disponibilité d'un service externe. Séparé des communes INSCRITES sur Civipass (`Municipality`) : une ligne
 * du référentiel n'ouvre aucun droit, ne crée aucune commune et ne porte aucune donnée commerciale. Le lien avec une commune
 * inscrite est le code INSEE, jamais une clé étrangère.
 *
 * Jamais supprimée : une commune qui disparaît de la source (fusion, changement de code) est désactivée (`active = false`,
 * `deactivatedAt`), donc plus proposée à l'invitation mais conservée ; elle est réactivée si elle réapparaît.
 */
#[ORM\Entity]
#[ORM\Table(name: 'municipality_national_commune')]
#[ORM\UniqueConstraint(name: 'uniq_national_commune_insee_code', columns: ['insee_code'])]
#[ORM\Index(name: 'idx_national_commune_search', columns: ['active', 'search_name'])]
class NationalCommune
{
    public const NAME_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 5, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
    private string $inseeCode;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** Nom normalisé pour la recherche : minuscules, sans accents, ponctuation réduite à des espaces. */
    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $searchName;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $departmentCode;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $regionCode;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $postalCodes;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $population;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    /** Identifiant de la dernière synchronisation qui a vu cette commune : sert à désactiver celles qu'elle n'a pas vues. */
    #[ORM\Column(type: Types::STRING, length: 36, options: ['fixed' => true])]
    private string $lastSyncId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        NationalCommuneRecord $record,
        #[ORM\Column(type: Types::STRING, length: 30)]
        private string $source,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $firstSeenAt,
        string $syncId = '00000000-0000-0000-0000-000000000000',
    ) {
        $this->id = Uuid::v7();
        $this->inseeCode = InseeCode::fromString($record->inseeCode)->value;
        $this->name = self::assertName($record->name);
        $this->searchName = self::normalize($this->name);
        $this->departmentCode = $record->departmentCode;
        $this->regionCode = $record->regionCode;
        $this->postalCodes = $record->postalCodes;
        $this->population = $record->population;
        $this->lastSeenAt = $firstSeenAt;
        $this->lastSyncId = $syncId;
        $this->updatedAt = $firstSeenAt;
    }

    /**
     * Normalisation commune au stockage et à la recherche : « L'Abergement-Clémenciat » → « l abergement clemenciat ».
     */
    public static function normalize(string $text): string
    {
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Lower()', $text);
        $ascii = false === $ascii ? mb_strtolower($text) : $ascii;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    private static function assertName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le nom d\'une commune du référentiel doit contenir de 1 à %d caractères.', self::NAME_MAX_LENGTH));
        }

        return $name;
    }

    /**
     * Aligne la commune sur la source ; renvoie vrai si quelque chose a changé (champ ou réactivation).
     */
    public function refresh(NationalCommuneRecord $record, string $source, \DateTimeImmutable $now): bool
    {
        $name = self::assertName($record->name);
        $changed = $name !== $this->name
            || $record->departmentCode !== $this->departmentCode
            || $record->regionCode !== $this->regionCode
            || $record->postalCodes !== $this->postalCodes
            || $record->population !== $this->population
            || $source !== $this->source
            || !$this->active;
        if (!$changed) {
            return false;
        }
        $this->name = $name;
        $this->searchName = self::normalize($name);
        $this->departmentCode = $record->departmentCode;
        $this->regionCode = $record->regionCode;
        $this->postalCodes = $record->postalCodes;
        $this->population = $record->population;
        $this->source = $source;
        $this->active = true;
        $this->deactivatedAt = null;
        $this->updatedAt = $now;

        return true;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function inseeCode(): InseeCode
    {
        return InseeCode::fromString($this->inseeCode);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function departmentCode(): string
    {
        return $this->departmentCode;
    }

    /** @return list<string> */
    public function postalCodes(): array
    {
        return $this->postalCodes;
    }

    public function searchName(): string
    {
        return $this->searchName;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Désactivation unitaire (la synchronisation désactive en bloc, par SQL, avec la même sémantique). */
    public function deactivate(\DateTimeImmutable $now): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->deactivatedAt = $now;
        $this->updatedAt = $now;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function lastSyncId(): string
    {
        return $this->lastSyncId;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
