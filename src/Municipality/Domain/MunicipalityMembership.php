<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Rattachement d'un utilisateur à une commune. Un seul par couple (utilisateur, commune).
 * Les modifications de rôles et d'activation passent par MembershipManager (audit dans la même transaction).
 */
#[ORM\Entity]
#[ORM\Table(name: 'municipality_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_municipality', columns: ['user_id', 'municipality_id'])]
class MunicipalityMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @var list<string> valeurs de MunicipalityRole, uniques et triées */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<MunicipalityRole> $roles
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        array $roles,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->roles = self::normalizeRoles($roles);
        $this->updatedAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return list<MunicipalityRole>
     */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->roles as $value) {
            $role = MunicipalityRole::tryFrom($value);
            if (null === $role) {
                throw new DomainRuleViolation(sprintf('Rôle communal inconnu en base : "%s".', $value));
            }
            $roles[] = $role;
        }

        return $roles;
    }

    public function hasRole(MunicipalityRole $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Champ audité : réservé à MembershipManager (vérifié par un test d'architecture).
     *
     * @param list<MunicipalityRole> $roles
     */
    public function changeRoles(array $roles, \DateTimeImmutable $now): void
    {
        $this->roles = self::normalizeRoles($roles);
        $this->updatedAt = $now;
    }

    /**
     * Champ audité : réservé à MembershipManager.
     */
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->updatedAt = $now;
    }

    /**
     * Champ audité : réservé à MembershipManager. Réactive la ligne existante (pas de doublon).
     *
     * @param list<MunicipalityRole> $roles
     */
    public function activate(array $roles, \DateTimeImmutable $now): void
    {
        $this->roles = self::normalizeRoles($roles);
        $this->active = true;
        $this->updatedAt = $now;
    }

    /**
     * @param array<mixed> $roles valeurs non fiables : chaque entrée est revérifiée
     *
     * @return list<string>
     */
    private static function normalizeRoles(array $roles): array
    {
        $values = [];
        foreach ($roles as $role) {
            if (!$role instanceof MunicipalityRole) {
                throw new DomainRuleViolation('Rôle communal invalide.');
            }
            $values[$role->value] = $role->value;
        }
        if ([] === $values) {
            throw new DomainRuleViolation('Un membership doit porter au moins un rôle.');
        }
        $values = array_values($values);
        sort($values);

        return $values;
    }
}
