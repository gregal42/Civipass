<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;

final class MembershipBuilder
{
    /** @var list<MunicipalityRole> */
    private array $roles = [MunicipalityRole::Instructor];
    private bool $active = true;

    public function __construct(private readonly User $user, private readonly Municipality $municipality)
    {
    }

    public static function aMembership(User $user, Municipality $municipality): self
    {
        return new self($user, $municipality);
    }

    public function withRoles(MunicipalityRole ...$roles): self
    {
        $this->roles = array_values($roles);

        return $this;
    }

    public function inactive(): self
    {
        $this->active = false;

        return $this;
    }

    public function build(): MunicipalityMembership
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $membership = new MunicipalityMembership($this->user, $this->municipality, $this->roles, $now);
        if (!$this->active) {
            $membership->deactivate($now);
        }

        return $membership;
    }
}
