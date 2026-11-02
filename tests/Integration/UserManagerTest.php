<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Application\UserManager;
use App\Municipality\Application\AuditLogReader;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\UserBuilder;

final class UserManagerTest extends DatabaseTestCase
{
    private UserManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(UserManager::class);
    }

    public function testCreateUserStoresAHashNotThePassword(): void
    {
        $user = $this->manager->createUser('  New.User@Example.test ', 'correct horse battery', 'Ada', 'Lovelace');

        self::assertSame('new.user@example.test', $user->email());
        self::assertNotSame('correct horse battery', $user->getPassword());
        self::assertStringStartsWith('$', $user->getPassword());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testCreateUserRejectsShortPasswordWithoutComplexityRule(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->manager->createUser('short@example.test', 'abc12345678', 'A', 'B');
    }

    public function testCreateUserRejectsDuplicateEmailWhateverTheCase(): void
    {
        $this->manager->createUser('same@example.test', 'correct horse battery', 'A', 'B');
        $this->expectException(DomainRuleViolation::class);
        $this->manager->createUser('SAME@example.test', 'another long password', 'C', 'D');
    }

    public function testGrantSuperAdminIsAuditedAsGlobalEntry(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $this->manager->grantSuperAdmin($user, null);

        self::assertTrue($user->isSuperAdmin());
        $this->em->clear();
        $logs = $this->service(AuditLogRepository::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame(AuditAction::UserSuperAdminChanged, $logs[0]->action());
        self::assertSame(AuditOrigin::Console, $logs[0]->origin());
        self::assertNull($logs[0]->municipalityId());
        self::assertSame(['superAdmin' => false], $logs[0]->previousValue());
        self::assertSame(['superAdmin' => true], $logs[0]->newValue());
    }

    public function testGrantSuperAdminByAnotherSuperAdminRecordsTheAuthor(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $user = UserBuilder::aUser()->build();
        $this->save($root, $user);

        $this->manager->grantSuperAdmin($user, $root);

        $this->em->clear();
        $log = $this->service(AuditLogRepository::class)->findAll()[0];
        self::assertSame(AuditOrigin::User, $log->origin());
        self::assertTrue($root->id()->equals($log->actorId()));
    }

    public function testNonSuperAdminCannotGrantSuperAdmin(): void
    {
        $plain = UserBuilder::aUser()->build();
        $target = UserBuilder::aUser()->build();
        $this->save($plain, $target);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->grantSuperAdmin($target, $plain);
    }

    public function testLastActiveSuperAdminCannotBeRevokedEvenByConsole(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($root);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->revokeSuperAdmin($root, null);
    }

    public function testLastActiveSuperAdminCannotBeDeactivated(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($root);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->deactivate($root, null);
    }

    public function testSuperAdminCanBeRevokedWhenAnotherActiveOneRemains(): void
    {
        $first = UserBuilder::aUser()->superAdmin()->build();
        $second = UserBuilder::aUser()->superAdmin()->build();
        $this->save($first, $second);

        $this->manager->revokeSuperAdmin($first, $second);

        self::assertFalse($first->isSuperAdmin());
        self::assertTrue($second->isSuperAdmin());
    }

    public function testAnInactiveSuperAdminDoesNotCountAsRemaining(): void
    {
        $active = UserBuilder::aUser()->superAdmin()->build();
        $inactive = UserBuilder::aUser()->superAdmin()->inactive()->build();
        $this->save($active, $inactive);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->revokeSuperAdmin($active, null);
    }

    public function testDeactivatedAccountCannotBecomeSuperAdmin(): void
    {
        $user = UserBuilder::aUser()->inactive()->build();
        $this->save($user);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->grantSuperAdmin($user, null);
    }

    public function testRecoveryByConsoleIsAGrantNeverABypass(): void
    {
        // Procédure exceptionnelle : sans aucun superadmin actif, la console peut en désigner un (audité),
        // mais elle ne peut pas retirer le dernier.
        $user = UserBuilder::aUser()->build();
        $this->save($user);
        $this->manager->grantSuperAdmin($user, null);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->revokeSuperAdmin($user, null);
    }

    public function testAuditReaderRefusesNonSuperAdmin(): void
    {
        $plain = UserBuilder::aUser()->build();
        $this->save($plain);

        $this->expectException(OperationNotAllowed::class);
        $this->service(AuditLogReader::class)->all($plain);
    }
}
