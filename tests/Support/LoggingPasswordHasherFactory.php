<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Décorateur de test de la fabrique de hacheurs : même algorithme, même coût (il délègue), mais chaque `hash` et chaque `verify` est consigné. Sert à prouver
 * qu'un échec de connexion pour identifiant inconnu coûte un hachage, et qu'une tentative limitée n'en coûte aucun.
 */
final class LoggingPasswordHasherFactory implements PasswordHasherFactoryInterface
{
    public function __construct(private readonly PasswordHasherFactoryInterface $inner, private readonly HasherFactoryLog $log)
    {
    }

    public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
    {
        $hasher = $this->inner->getPasswordHasher($user);
        $log = $this->log;

        return new class ($hasher, $log) implements PasswordHasherInterface {
            public function __construct(private readonly PasswordHasherInterface $inner, private readonly HasherFactoryLog $log)
            {
            }

            public function hash(#[\SensitiveParameter] string $plainPassword): string
            {
                $this->log->add('hash');

                return $this->inner->hash($plainPassword);
            }

            public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
            {
                $this->log->add('verify');

                return $this->inner->verify($hashedPassword, $plainPassword);
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return $this->inner->needsRehash($hashedPassword);
            }
        };
    }
}
