<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Infrastructure\UserProvider;
use App\Municipality\Application\AuditLogReader;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Application\MunicipalityManager;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class AccessAndAuditTest extends DatabaseTestCase
{
    public function testEffectiveRightsRequireActiveUserMembershipAndMunicipality(): void
    {
        $policy = $this->service(MembershipAccessPolicy::class);
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $active = UserBuilder::aUser()->build();
        $inactiveUser = UserBuilder::aUser()->inactive()->build();
        $inactiveMember = UserBuilder::aUser()->build();
        $this->save(
            $municipality,
            $active,
            $inactiveUser,
            $inactiveMember,
            MembershipBuilder::aMembership($active, $municipality)->withRoles(MunicipalityRole::Instructor)->build(),
            MembershipBuilder::aMembership($inactiveUser, $municipality)->withRoles(MunicipalityRole::Instructor)->build(),
            MembershipBuilder::aMembership($inactiveMember, $municipality)->withRoles(MunicipalityRole::Instructor)->inactive()->build(),
        );

        self::assertTrue($policy->hasRole($active, $municipality, MunicipalityRole::Instructor));
        self::assertFalse($policy->hasRole($active, $municipality, MunicipalityRole::EventManager), 'Pas de rôle implicite.');
        self::assertFalse($policy->hasRole($inactiveUser, $municipality, MunicipalityRole::Instructor), 'Compte désactivé.');
        self::assertFalse($policy->hasRole($inactiveMember, $municipality, MunicipalityRole::Instructor), 'Membership inactif.');
    }

    public function testSuspendedMunicipalityIsReadOnlyAndInactiveHasNoAccess(): void
    {
        $policy = $this->service(MembershipAccessPolicy::class);
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $user = UserBuilder::aUser()->build();
        $this->save($municipality, $user, MembershipBuilder::aMembership($user, $municipality)->withRoles(MunicipalityRole::Instructor)->build());
        $manager = $this->service(MunicipalityManager::class);

        $manager->changeStatus($municipality, MunicipalityStatus::Suspended, null);
        self::assertTrue($policy->hasRole($user, $municipality, MunicipalityRole::Instructor, write: false));
        self::assertFalse($policy->hasRole($user, $municipality, MunicipalityRole::Instructor, write: true));

        $manager->changeStatus($municipality, MunicipalityStatus::Inactive, null);
        self::assertFalse($policy->hasRole($user, $municipality, MunicipalityRole::Instructor, write: false));
    }

    public function testSuperAdminGetsNoImplicitCommunalRole(): void
    {
        $policy = $this->service(MembershipAccessPolicy::class);
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($municipality, $root);

        self::assertFalse($policy->hasRole($root, $municipality, MunicipalityRole::Instructor));
        self::assertTrue($policy->canManageMembers($root, $municipality), 'Supervision explicite.');
    }

    #[DataProvider('forgedIds')]
    public function testContextRefusesForgedOrForeignMunicipalityIds(string $kind): void
    {
        $a = MunicipalityBuilder::aMunicipality()->build();
        $b = MunicipalityBuilder::aMunicipality()->build();
        $agentA = UserBuilder::aUser()->build();
        $this->save($a, $b, $agentA, MembershipBuilder::aMembership($agentA, $a)->build());
        $id = match ($kind) {
            'autre commune' => $b->id()->toRfc4122(),
            'uuid inexistant' => '0198f6a0-0000-7000-8000-000000000000',
            default => "' OR 1=1 --",
        };

        $this->expectException(OperationNotAllowed::class);
        $this->service(MunicipalityContext::class)->requireMembership($agentA, $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedIds(): iterable
    {
        yield 'autre commune' => ['autre commune'];
        yield 'uuid inexistant' => ['uuid inexistant'];
        yield 'chaîne invalide' => ['chaîne invalide'];
    }

    public function testContextReturnsOwnMembership(): void
    {
        $a = MunicipalityBuilder::aMunicipality()->build();
        $agent = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($agent, $a)->build();
        $this->save($a, $agent, $membership);

        $resolved = $this->service(MunicipalityContext::class)->requireMembership($agent, $a->id()->toRfc4122());
        self::assertTrue($membership->id()->equals($resolved->id()));
    }

    public function testAuditIsScopedByMunicipalityAndNeverLeaksGlobalEntries(): void
    {
        [$a, $b, $adminA, $adminB, $root] = $this->twoMunicipalitiesWithAuditedActivity();
        $reader = $this->service(AuditLogReader::class);

        $logsA = $reader->forMunicipality($adminA, $a);
        self::assertNotSame([], $logsA);
        foreach ($logsA as $log) {
            self::assertTrue($a->id()->equals($log->municipalityId()), 'Admin A ne voit que la commune A.');
        }

        foreach ($reader->forMunicipality($adminB, $b) as $log) {
            self::assertTrue($b->id()->equals($log->municipalityId()));
        }

        $all = $reader->all($root);
        self::assertContains(null, array_map(static fn ($l) => $l->municipalityId(), $all), 'Le superadmin voit les entrées globales.');
    }

    public function testAdminCannotReadAuditOfAnotherMunicipality(): void
    {
        [$a, , , $adminB] = $this->twoMunicipalitiesWithAuditedActivity();

        $this->expectException(OperationNotAllowed::class);
        $this->service(AuditLogReader::class)->forMunicipality($adminB, $a);
    }

    public function testInstructorCannotReadAudit(): void
    {
        [$a] = $this->twoMunicipalitiesWithAuditedActivity();
        $instructor = UserBuilder::aUser()->build();
        $this->save($instructor, MembershipBuilder::aMembership($instructor, $a)->build());

        $this->expectException(OperationNotAllowed::class);
        $this->service(AuditLogReader::class)->forMunicipality($instructor, $a);
    }

    public function testUserProviderFindsUserWithNonNormalizedIdentifier(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('login@example.test')->build());
        $provider = $this->service(UserProvider::class);

        self::assertSame('login@example.test', $provider->loadUserByIdentifier('  LOGIN@Example.TEST ')->getUserIdentifier());
    }

    #[DataProvider('unknownIdentifiers')]
    public function testUserProviderRejectsUnknownOrMalformedIdentifiers(string $identifier): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->service(UserProvider::class)->loadUserByIdentifier($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIdentifiers(): iterable
    {
        yield 'inconnu' => ['nobody@example.test'];
        yield 'malformé' => ['pas un email'];
    }

    /**
     * @return array{Municipality, Municipality, \App\Identity\Domain\User, \App\Identity\Domain\User, \App\Identity\Domain\User}
     */
    private function twoMunicipalitiesWithAuditedActivity(): array
    {
        $a = MunicipalityBuilder::aMunicipality()->build();
        $b = MunicipalityBuilder::aMunicipality()->build();
        $adminA = UserBuilder::aUser()->build();
        $adminB = UserBuilder::aUser()->build();
        $root = UserBuilder::aUser()->superAdmin()->build();
        $agentA = UserBuilder::aUser()->build();
        $agentB = UserBuilder::aUser()->build();
        $this->save(
            $a,
            $b,
            $adminA,
            $adminB,
            $root,
            $agentA,
            $agentB,
            MembershipBuilder::aMembership($adminA, $a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($adminB, $b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
        );
        $manager = $this->service(MembershipManager::class);
        $manager->grant($agentA, $a, [MunicipalityRole::Instructor], $adminA);
        $manager->grant($agentB, $b, [MunicipalityRole::Instructor], $adminB);
        $this->service(\App\Identity\Application\UserManager::class)->grantSuperAdmin($agentA, $root);

        return [$a, $b, $adminA, $adminB, $root];
    }
}
