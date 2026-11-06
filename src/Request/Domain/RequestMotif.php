<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Motif d'une demande (DM-097) : référentiel global à la plateforme, jamais supprimé (FK RESTRICT) ; une entrée
 * désactivée n'est plus proposée mais reste lisible. `needsDetail` rend la précision obligatoire (« Autre »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_motif')]
#[ORM\UniqueConstraint(name: 'uniq_request_motif_code', columns: ['code'])]
class RequestMotif
{
    public const LABEL_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 40, options: ['collation' => 'utf8mb4_bin'])]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: self::LABEL_MAX_LENGTH)]
    private string $label;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $needsDetail;

    public function __construct(string $code, string $label, int $position, bool $needsDetail = false)
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            throw new DomainRuleViolation('Code de motif invalide : minuscules, chiffres et « _ » (40 caractères maximum).');
        }
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le libellé du motif est obligatoire (%d caractères maximum).', self::LABEL_MAX_LENGTH));
        }
        if ($position < 0 || $position > 32767) {
            throw new DomainRuleViolation('Position d\'affichage invalide.');
        }
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->label = $label;
        $this->position = $position;
        $this->needsDetail = $needsDetail;
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

    public function position(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function needsDetail(): bool
    {
        return $this->needsDetail;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
