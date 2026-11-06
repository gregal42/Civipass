<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Catégorie de bénéficiaire (DM-098) : référentiel global distinct du motif, jamais supprimé (FK RESTRICT) ; une
 * entrée désactivée n'est plus proposée mais reste lisible. La catégorie n'accorde aucun droit automatique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_beneficiary_category')]
#[ORM\UniqueConstraint(name: 'uniq_request_beneficiary_category_code', columns: ['code'])]
class BeneficiaryCategory
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

    public function __construct(string $code, string $label, int $position)
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            throw new DomainRuleViolation('Code de catégorie invalide : minuscules, chiffres et « _ » (40 caractères maximum).');
        }
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le libellé de la catégorie est obligatoire (%d caractères maximum).', self::LABEL_MAX_LENGTH));
        }
        if ($position < 0 || $position > 32767) {
            throw new DomainRuleViolation('Position d\'affichage invalide.');
        }
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->label = $label;
        $this->position = $position;
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

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
