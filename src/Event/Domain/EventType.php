<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Donnée de référence évolutive (DM-053, DT-023). Le code technique est unique et immuable ; un type utilisé
 * ne se supprime pas (FK RESTRICT, aucune méthode de suppression). Pas d'administration au LOT 02.
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_type')]
#[ORM\UniqueConstraint(name: 'uniq_event_type_code', columns: ['code'])]
class EventType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 40, options: ['collation' => 'utf8mb4_bin'])]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $label;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $code, string $label, int $sortOrder = 0)
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            throw new DomainRuleViolation('Code de type d\'événement invalide : minuscules, chiffres et « _ » (40 caractères maximum).');
        }
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->label = self::normalizeLabel($label);
        $this->sortOrder = self::normalizeSortOrder($sortOrder);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function relabel(string $label): void
    {
        $this->label = self::normalizeLabel($label);
    }

    public function reorder(int $sortOrder): void
    {
        $this->sortOrder = self::normalizeSortOrder($sortOrder);
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > 100) {
            throw new DomainRuleViolation('Le libellé du type d\'événement est obligatoire (100 caractères maximum).');
        }

        return $label;
    }

    private static function normalizeSortOrder(int $sortOrder): int
    {
        if ($sortOrder < 0 || $sortOrder > 32767) {
            throw new DomainRuleViolation('Ordre d\'affichage invalide.');
        }

        return $sortOrder;
    }
}
