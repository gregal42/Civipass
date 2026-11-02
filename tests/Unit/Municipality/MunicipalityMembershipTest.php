<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class MunicipalityMembershipTest extends TestCase
{
    private const NOW = '2026-02-01 12:00:00';

    public function testRolesAreDeduplicatedAndSorted(): void
    {
        $membership = $this->membership(MunicipalityRole::Instructor, MunicipalityRole::EventManager, MunicipalityRole::Instructor);
        self::assertSame([MunicipalityRole::EventManager, MunicipalityRole::Instructor], $membership->roles());
    }

    public function testRolesAreCumulativeWithoutHierarchy(): void
    {
        $membership = $this->membership(MunicipalityRole::MunicipalityAdmin);
        self::assertTrue($membership->hasRole(MunicipalityRole::MunicipalityAdmin));
        self::assertFalse($membership->hasRole(MunicipalityRole::Instructor), 'ADMIN n\'implique pas INSTRUCTOR.');
        self::assertFalse($membership->hasRole(MunicipalityRole::EventManager));
    }

    public function testMembershipWithoutRoleIsRejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        new MunicipalityMembership(UserBuilder::aUser()->build(), MunicipalityBuilder::aMunicipality()->build(), [], new \DateTimeImmutable(self::NOW));
    }

    public function testActivationRequiresAtLeastOneRole(): void
    {
        $membership = $this->membership(MunicipalityRole::Instructor);
        $membership->deactivate(new \DateTimeImmutable(self::NOW));
        $this->expectException(DomainRuleViolation::class);
        $membership->activate([], new \DateTimeImmutable(self::NOW));
    }

    public function testUnknownRoleInDatabaseFailsLoudly(): void
    {
        $membership = $this->membership(MunicipalityRole::Instructor);
        $property = new \ReflectionProperty($membership, 'roles');
        $property->setValue($membership, ['SUPER_POWER']);

        $this->expectException(DomainRuleViolation::class);
        $membership->roles();
    }

    public function testDeactivateKeepsRolesForHistory(): void
    {
        $membership = $this->membership(MunicipalityRole::Instructor);
        $membership->deactivate(new \DateTimeImmutable(self::NOW));
        self::assertFalse($membership->isActive());
        self::assertSame([MunicipalityRole::Instructor], $membership->roles());
    }

    private function membership(MunicipalityRole ...$roles): MunicipalityMembership
    {
        return MembershipBuilder::aMembership(UserBuilder::aUser()->build(), MunicipalityBuilder::aMunicipality()->build())
            ->withRoles(...$roles)->build();
    }
}
