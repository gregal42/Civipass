<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;

final class UserBuilder
{
    private static int $sequence = 0;

    private ?string $email = null;
    private bool $superAdmin = false;
    private bool $active = true;

    public static function aUser(): self
    {
        return new self();
    }

    public function withEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function superAdmin(): self
    {
        $this->superAdmin = true;

        return $this;
    }

    public function inactive(): self
    {
        $this->active = false;

        return $this;
    }

    public function build(): User
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $email = $this->email ?? sprintf('user%d@example.test', ++self::$sequence);
        $user = new User(EmailAddress::fromString($email), 'hash', 'Prénom', 'Nom', $now);
        if ($this->superAdmin) {
            $user->changeSuperAdmin(true, $now);
        }
        if (!$this->active) {
            $user->deactivate($now);
        }

        return $user;
    }
}
