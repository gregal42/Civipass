<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Application\UserManager;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

final class UserDeactivationTest extends DatabaseTestCase
{
    private UserManager $manager;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(UserManager::class);
        $this->root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($this->root);
    }

    public function testDeactivationIsRefusedWhenItLeavesAnActiveMunicipalityWithoutAdministrator(): void
    {
        [$user] = $this->administratorOf(MunicipalityBuilder::aMunicipality()->build());

        $this->expectException(OperationNotAllowed::class);
        $this->manager->deactivate($user, $this->root);
    }

    public function testEveryMunicipalityOfTheUserIsChecked(): void
    {
        $user = UserBuilder::aUser()->build();
        $covered = MunicipalityBuilder::aMunicipality()->build();
        $sole = MunicipalityBuilder::aMunicipality()->build();
        $other = UserBuilder::aUser()->build();
        $this->save(
            $user,
            $other,
            $covered,
            $sole,
            MembershipBuilder::aMembership($user, $covered)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($other, $covered)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($user, $sole)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
        );

        try {
            $this->manager->deactivate($user, $this->root);
            self::fail('La commune « sole » aurait dû bloquer la désactivation.');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString($sole->name(), $e->getMessage());
        }
    }

    public function testDeactivationIsAllowedWhenAnotherEffectiveAdministratorRemainsEverywhere(): void
    {
        $user = UserBuilder::aUser()->build();
        $other = UserBuilder::aUser()->build();
        $a = MunicipalityBuilder::aMunicipality()->build();
        $b = MunicipalityBuilder::aMunicipality()->build();
        $this->save(
            $user,
            $other,
            $a,
            $b,
            MembershipBuilder::aMembership($user, $a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($user, $b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($other, $a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($other, $b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
        );

        $this->manager->deactivate($user, $this->root);

        self::assertFalse($user->isActive());
    }

    public function testOnlyEffectiveAdministratorsCount(): void
    {
        // Les autres « administrateurs » sont inopérants : compte désactivé, membership inactif, rôle non admin.
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        [$user] = $this->administratorOf($municipality);
        $disabledAccount = UserBuilder::aUser()->inactive()->build();
        $inactiveMembership = UserBuilder::aUser()->build();
        $notAdmin = UserBuilder::aUser()->build();
        $this->save(
            $disabledAccount,
            $inactiveMembership,
            $notAdmin,
            MembershipBuilder::aMembership($disabledAccount, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($inactiveMembership, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->inactive()->build(),
            MembershipBuilder::aMembership($notAdmin, $municipality)->withRoles(MunicipalityRole::Instructor, MunicipalityRole::EventManager)->build(),
        );

        $this->expectException(OperationNotAllowed::class);
        $this->manager->deactivate($user, null);
    }

    public function testSuspendedOrInactiveMunicipalityDoesNotBlockDeactivation(): void
    {
        foreach ([MunicipalityStatus::Suspended, MunicipalityStatus::Inactive] as $status) {
            [$user] = $this->administratorOf(MunicipalityBuilder::aMunicipality()->withStatus($status)->build());

            $this->manager->deactivate($user, $this->root);

            self::assertFalse($user->isActive(), $status->value);
        }
    }

    public function testInactiveAdministrativeMembershipDoesNotBlockDeactivation(): void
    {
        $user = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($user, $municipality, MembershipBuilder::aMembership($user, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->inactive()->build());

        $this->manager->deactivate($user, null);

        self::assertFalse($user->isActive());
    }

    public function testDeactivationAndReactivationAreAudited(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $this->manager->deactivate($user, $this->root);
        $this->manager->activate($user, null);

        $this->em->clear();
        $logs = array_values(array_filter(
            $this->service(AuditLogRepository::class)->findAll(),
            static fn ($l): bool => AuditAction::UserActivationChanged === $l->action(),
        ));
        self::assertCount(2, $logs);
        // Plus récent en premier : réactivation (console), puis désactivation (superadmin).
        self::assertSame(AuditOrigin::Console, $logs[0]->origin());
        self::assertSame(['active' => false], $logs[0]->previousValue());
        self::assertSame(['active' => true], $logs[0]->newValue());
        self::assertSame(AuditOrigin::User, $logs[1]->origin());
        self::assertTrue($this->root->id()->equals($logs[1]->actorId()));
        self::assertSame(['active' => true], $logs[1]->previousValue());
        self::assertSame(['active' => false], $logs[1]->newValue());
        self::assertNull($logs[1]->municipalityId());
    }

    public function testNoAuditEntryWhenTheStateDoesNotChange(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $this->manager->activate($user, null);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    public function testGrantSuperAdminReadsTheLockedAndRefreshedState(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);
        // Une autre requête désactive le compte pendant que celle-ci détient un objet périmé.
        $this->em->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$user->id()->toBinary()]);
        self::assertTrue($user->isActive(), 'précondition : l\'objet en mémoire est périmé');

        $this->expectException(OperationNotAllowed::class);
        $this->manager->grantSuperAdmin($user, null);
    }

    public function testDeactivationRowLockBlocksConcurrentChangesToTheSameUser(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);
        $connection = $this->em->getConnection();
        $other = DriverManager::getConnection($connection->getParams());
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $connection->beginTransaction();
        try {
            $this->service(\App\Identity\Domain\UserRepository::class)->lock($user);

            $this->expectException(LockWaitTimeoutException::class);
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM identity_user WHERE id = ? FOR UPDATE', [$user->id()->toBinary()]);
        } finally {
            $other->close();
            $connection->rollBack();
        }
    }

    /**
     * @return array{User, Municipality}
     */
    private function administratorOf(Municipality $municipality): array
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user, $municipality, MembershipBuilder::aMembership($user, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        return [$user, $municipality];
    }
}
