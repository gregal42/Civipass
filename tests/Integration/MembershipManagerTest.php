<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Psr\Clock\ClockInterface;

final class MembershipManagerTest extends DatabaseTestCase
{
    private MembershipManager $manager;
    private Municipality $a;
    private Municipality $b;
    private User $adminA;
    private User $adminB;
    private MunicipalityMembership $adminAMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(MembershipManager::class);
        $this->a = MunicipalityBuilder::aMunicipality()->withInseeCode('75001')->build();
        $this->b = MunicipalityBuilder::aMunicipality()->withInseeCode('69001')->build();
        $this->adminA = UserBuilder::aUser()->build();
        $this->adminB = UserBuilder::aUser()->build();
        $this->adminAMembership = MembershipBuilder::aMembership($this->adminA, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save(
            $this->a,
            $this->b,
            $this->adminA,
            $this->adminB,
            $this->adminAMembership,
            MembershipBuilder::aMembership($this->adminB, $this->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
        );
    }

    public function testGrantCreatesMembershipAndExactlyOneAuditEntry(): void
    {
        $agent = UserBuilder::aUser()->build();
        $this->save($agent);

        $membership = $this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], $this->adminA);

        $logs = $this->audit()->findByMunicipality($this->a->id());
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertSame(AuditAction::MembershipCreated, $log->action());
        self::assertSame(AuditOrigin::User, $log->origin());
        self::assertTrue($this->adminA->id()->equals($log->actorId()));
        self::assertTrue($membership->id()->equals($log->subjectId()));
        self::assertNull($log->previousValue());
        self::assertSame(['roles' => ['INSTRUCTOR'], 'active' => true], $log->newValue());
        self::assertSame('UTC', $log->occurredAt()->getTimezone()->getName());
    }

    public function testConsoleActorIsRecordedWithoutUserId(): void
    {
        $agent = UserBuilder::aUser()->build();
        $this->save($agent);

        $this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], null);

        $log = $this->audit()->findByMunicipality($this->a->id())[0];
        self::assertSame(AuditOrigin::Console, $log->origin());
        self::assertNull($log->actorId());
    }

    public function testAdminOfAnotherMunicipalityCannotGrantEvenWithForgedIds(): void
    {
        $agent = UserBuilder::aUser()->build();
        $this->save($agent);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], $this->adminB);
        } finally {
            self::assertSame([], $this->audit()->findByMunicipality($this->a->id()));
        }
    }

    public function testInstructorCannotGrant(): void
    {
        $instructor = UserBuilder::aUser()->build();
        $agent = UserBuilder::aUser()->build();
        $this->save($instructor, $agent, MembershipBuilder::aMembership($instructor, $this->a)->build());

        $this->expectException(OperationNotAllowed::class);
        $this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], $instructor);
    }

    public function testSuperAdminCanGrant(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $agent = UserBuilder::aUser()->build();
        $this->save($root, $agent);

        $membership = $this->manager->grant($agent, $this->a, [MunicipalityRole::EventManager], $root);

        self::assertTrue($membership->isActive());
    }

    public function testGrantOnInactiveMembershipReactivatesTheSameRow(): void
    {
        $agent = UserBuilder::aUser()->build();
        $inactive = MembershipBuilder::aMembership($agent, $this->a)->inactive()->build();
        $this->save($agent, $inactive);

        $membership = $this->manager->grant($agent, $this->a, [MunicipalityRole::EventManager], $this->adminA);

        self::assertTrue($membership->id()->equals($inactive->id()));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE municipality_id = ?', [$this->a->id()->toBinary()]));
        $log = $this->audit()->findByMunicipality($this->a->id())[0];
        self::assertSame(AuditAction::MembershipActivated, $log->action());
        self::assertFalse($log->previousValue()['active'] ?? null);
        self::assertTrue($log->newValue()['active']);
    }

    public function testGrantOnActiveMembershipIsRefused(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->manager->grant($this->adminA, $this->a, [MunicipalityRole::Instructor], null);
    }

    public function testChangeRolesRecordsBeforeAndAfter(): void
    {
        $agent = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($agent, $this->a)->withRoles(MunicipalityRole::Instructor)->build();
        $this->save($agent, $membership);

        $this->manager->changeRoles($membership, [MunicipalityRole::Instructor, MunicipalityRole::EventManager], $this->adminA);

        $log = $this->audit()->findByMunicipality($this->a->id())[0];
        self::assertSame(AuditAction::MembershipRolesChanged, $log->action());
        self::assertSame(['roles' => ['INSTRUCTOR'], 'active' => true], $log->previousValue());
        self::assertSame(['roles' => ['EVENT_MANAGER', 'INSTRUCTOR'], 'active' => true], $log->newValue());
    }

    public function testChangeRolesWithSameRolesWritesNoAuditEntry(): void
    {
        $agent = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($agent, $this->a)->withRoles(MunicipalityRole::Instructor)->build();
        $this->save($agent, $membership);

        $this->manager->changeRoles($membership, [MunicipalityRole::Instructor], $this->adminA);

        self::assertSame([], $this->audit()->findByMunicipality($this->a->id()));
    }

    public function testSelfElevationIsRefused(): void
    {
        $instructor = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($instructor, $this->a)->build();
        $this->save($instructor, $membership);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->changeRoles($membership, [MunicipalityRole::MunicipalityAdmin], $instructor);
    }

    public function testAdminOfAnotherMunicipalityCannotChangeRoles(): void
    {
        $agent = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($agent, $this->a)->build();
        $this->save($agent, $membership);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->changeRoles($membership, [MunicipalityRole::EventManager], $this->adminB);
    }

    public function testLastActiveAdminCannotBeDeactivated(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->manager->deactivate($this->adminAMembership, $this->adminA);
    }

    public function testLastActiveAdminCannotLoseAdminRole(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->manager->changeRoles($this->adminAMembership, [MunicipalityRole::Instructor], null);
    }

    public function testAdminCanBeRemovedWhenAnotherActiveAdminRemains(): void
    {
        $second = UserBuilder::aUser()->build();
        $this->save($second, MembershipBuilder::aMembership($second, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $this->manager->deactivate($this->adminAMembership, $second);

        self::assertFalse($this->adminAMembership->isActive());
    }

    public function testAnAdminWhoseAccountIsDisabledDoesNotCountAsRemainingAdmin(): void
    {
        $disabled = UserBuilder::aUser()->inactive()->build();
        $this->save($disabled, MembershipBuilder::aMembership($disabled, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $this->expectException(OperationNotAllowed::class);
        $this->manager->deactivate($this->adminAMembership, null);
    }

    public function testSuspendedMunicipalityAdminCannotManageMembers(): void
    {
        $this->a->changeStatus(MunicipalityStatus::Suspended, new \DateTimeImmutable());
        $agent = UserBuilder::aUser()->build();
        $this->save($agent);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], $this->adminA);
    }

    public function testSuperAdminCanManageMembersOfSuspendedMunicipality(): void
    {
        $this->a->changeStatus(MunicipalityStatus::Suspended, new \DateTimeImmutable());
        $root = UserBuilder::aUser()->superAdmin()->build();
        $agent = UserBuilder::aUser()->build();
        $this->save($root, $agent);

        self::assertTrue($this->manager->grant($agent, $this->a, [MunicipalityRole::Instructor], $root)->isActive());
    }

    public function testFailedAuditWritesRollBackTheMembershipChange(): void
    {
        $agent = UserBuilder::aUser()->build();
        $membership = MembershipBuilder::aMembership($agent, $this->a)->withRoles(MunicipalityRole::Instructor)->build();
        $this->save($agent, $membership);
        $membershipId = $membership->id();

        $failingAudit = new class () implements AuditTrail {
            public function record(AuditEvent $event): void
            {
                throw new \RuntimeException('audit indisponible');
            }
        };
        $manager = new MembershipManager(
            $this->em,
            $this->service(TransactionRunner::class),
            $this->service(MunicipalityRepository::class),
            $this->service(MembershipRepository::class),
            $this->service(MembershipAccessPolicy::class),
            $failingAudit,
            $this->service(ClockInterface::class),
        );

        try {
            $manager->changeRoles($membership, [MunicipalityRole::EventManager], $this->adminA);
            self::fail('Une exception était attendue.');
        } catch (\RuntimeException) {
        }
        self::assertFalse($this->em->isOpen(), 'Une défaillance technique ferme l\'EntityManager.');

        $connection = $this->em->getConnection();
        self::assertSame('["INSTRUCTOR"]', $connection->fetchOne('SELECT roles FROM municipality_membership WHERE id = ?', [$membershipId->toBinary()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    public function testMunicipalityRowLockSerializesConcurrentMembershipChanges(): void
    {
        $connection = $this->em->getConnection();
        $other = DriverManager::getConnection($connection->getParams());
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $connection->beginTransaction();
        try {
            $this->service(MunicipalityRepository::class)->lock($this->a->id());

            $this->expectException(LockWaitTimeoutException::class);
            $other->beginTransaction();
            $other->fetchOne('SELECT id FROM municipality WHERE id = ? FOR UPDATE', [$this->a->id()->toBinary()]);
        } finally {
            $other->close();
            $connection->rollBack();
        }
    }

    private function audit(): AuditLogRepository
    {
        $this->em->clear();

        return $this->service(AuditLogRepository::class);
    }

    public function testAdministratorWhoseAccountIsAlreadyDisabledCanBeRemovedWithoutBlockingTheCommune(): void
    {
        // Seul « administrateur » : un compte désactivé. Le retirer ne change rien aux droits effectifs.
        $disabled = UserBuilder::aUser()->inactive()->build();
        $membership = MembershipBuilder::aMembership($disabled, $this->b)->withRoles(MunicipalityRole::Instructor)->build();
        $adminOfDisabled = MembershipBuilder::aMembership($disabled, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save($disabled, $membership, $adminOfDisabled);
        $this->adminAMembership->deactivate(new \DateTimeImmutable());
        $this->em->flush();

        $this->manager->deactivate($adminOfDisabled, null);

        self::assertFalse($adminOfDisabled->isActive());
    }
}
