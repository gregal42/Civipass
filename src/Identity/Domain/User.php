<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Les rôles communaux ne figurent JAMAIS dans getRoles() : ils relèvent de MunicipalityMembership.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_user')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 180, options: ['collation' => 'utf8mb4_bin'])]
    private string $email;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $superAdmin = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EmailAddress $email,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $password,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $firstName,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $lastName,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        self::assertName($firstName, 'prénom');
        self::assertName($lastName, 'nom');
        if ('' === $password) {
            throw new DomainRuleViolation('Le hash du mot de passe est obligatoire.');
        }
        $this->id = Uuid::v7();
        $this->email = $email->value;
        $this->updatedAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Rôles globaux uniquement.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->superAdmin ? ['ROLE_USER', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->email === $this->email
            && $user->password === $this->password
            && $user->active === $this->active
            && $user->superAdmin === $this->superAdmin;
    }

    public function changePassword(string $passwordHash, \DateTimeImmutable $now): void
    {
        if ('' === $passwordHash) {
            throw new DomainRuleViolation('Le hash du mot de passe est obligatoire.');
        }
        $this->password = $passwordHash;
        $this->updatedAt = $now;
    }

    public function rename(string $firstName, string $lastName, \DateTimeImmutable $now): void
    {
        self::assertName($firstName, 'prénom');
        self::assertName($lastName, 'nom');
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->updatedAt = $now;
    }

    /**
     * Réservé à UserManager, qui protège le dernier superadmin actif.
     */
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->updatedAt = $now;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->updatedAt = $now;
    }

    /**
     * Champ audité : réservé à UserManager (vérifié par un test d'architecture).
     */
    public function changeSuperAdmin(bool $superAdmin, \DateTimeImmutable $now): void
    {
        $this->superAdmin = $superAdmin;
        $this->updatedAt = $now;
    }

    /**
     * Mise à jour ciblée de la connexion : n'altère pas updatedAt.
     */
    public function recordLogin(\DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }

    private static function assertName(string $value, string $label): void
    {
        if ('' === trim($value) || mb_strlen($value) > 100) {
            throw new DomainRuleViolation(sprintf('Le %s est obligatoire (100 caractères maximum).', $label));
        }
    }
}
