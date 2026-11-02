<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Charge l'utilisateur par email NORMALISÉ (la colonne est comparée en binaire).
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $user = $this->users->findByEmail(EmailAddress::fromString($identifier));
        } catch (DomainRuleViolation) {
            $user = null;
        }
        if (null === $user) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Utilisateur non supporté : %s.', $user::class));
        }

        $fresh = $this->users->find($user->id());
        if (null === $fresh) {
            throw new UserNotFoundException();
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }
}
