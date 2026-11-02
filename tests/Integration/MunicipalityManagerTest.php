<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Municipality\Application\MunicipalityManager;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;

final class MunicipalityManagerTest extends DatabaseTestCase
{
    private MunicipalityManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(MunicipalityManager::class);
    }

    public function testSuperAdminCreatesMunicipalityAndDuplicateInseeIsRefused(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($root);

        $municipality = $this->manager->create('Paris 1er', '75101', $root);
        self::assertSame('75101', $municipality->inseeCode()->value);

        $this->expectException(DomainRuleViolation::class);
        $this->manager->create('Doublon', '75101', $root);
    }

    public function testMunicipalityAdminCannotCreateMunicipality(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $this->expectException(OperationNotAllowed::class);
        $this->manager->create('Pirate', '75102', $user);
    }

    public function testStatusChangeIsAuditedWithBeforeAfterAndAuthor(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($root, $municipality);

        $this->manager->changeStatus($municipality, MunicipalityStatus::Suspended, $root);

        $this->em->clear();
        $logs = $this->service(AuditLogRepository::class)->findByMunicipality($municipality->id());
        self::assertCount(1, $logs);
        self::assertSame(AuditAction::MunicipalityStatusChanged, $logs[0]->action());
        self::assertSame(AuditOrigin::User, $logs[0]->origin());
        self::assertTrue($root->id()->equals($logs[0]->actorId()));
        self::assertSame(['status' => 'ACTIVE'], $logs[0]->previousValue());
        self::assertSame(['status' => 'SUSPENDED'], $logs[0]->newValue());
    }

    public function testStatusChangeToTheSameValueWritesNothing(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($municipality);

        $this->manager->changeStatus($municipality, MunicipalityStatus::Active, null);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    public function testMunicipalityAdminCannotChangeStatus(): void
    {
        $admin = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($admin, $municipality, MembershipBuilder::aMembership($admin, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $this->expectException(OperationNotAllowed::class);
        $this->manager->changeStatus($municipality, MunicipalityStatus::Inactive, $admin);
    }

    public function testAdminUpdatesOwnContactWithE164Phone(): void
    {
        $admin = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($admin, $municipality, MembershipBuilder::aMembership($admin, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $this->manager->updateContact($municipality, 'Marie Martin', 'Mairie@Example.FR', '04 72 00 00 00', '1 place de la Mairie', '69001', 'Lyon', $admin);

        $this->em->clear();
        $reloaded = $this->em->find($municipality::class, $municipality->id());
        self::assertSame('+33472000000', $reloaded->contactPhone());
        self::assertSame('mairie@example.fr', $reloaded->contactEmail());
    }

    public function testAdminOfAnotherMunicipalityCannotUpdateContact(): void
    {
        $adminB = UserBuilder::aUser()->build();
        $a = MunicipalityBuilder::aMunicipality()->build();
        $b = MunicipalityBuilder::aMunicipality()->build();
        $this->save($adminB, $a, $b, MembershipBuilder::aMembership($adminB, $b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $this->expectException(OperationNotAllowed::class);
        $this->manager->updateContact($a, 'X', null, null, null, null, null, $adminB);
    }

    public function testInvalidPhoneIsRejectedServerSide(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($root, $municipality);

        $this->expectException(DomainRuleViolation::class);
        $this->manager->updateContact($municipality, null, null, '12 34', null, null, null, $root);
    }

    public function testContactChangeIsAuditedWithAuthorAndBeforeAfterValues(): void
    {
        $admin = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($admin, $municipality, MembershipBuilder::aMembership($admin, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->manager->updateContact($municipality, 'Marie Martin', 'a@example.fr', '04 72 00 00 00', '1 place', '69001', 'Lyon', $admin);

        $this->manager->updateContact($municipality, 'Paul Durand', 'b@example.fr', '+33 4 72 00 00 01', '2 place', '69002', 'Lyon', $admin);

        $this->em->clear();
        $logs = $this->service(AuditLogRepository::class)->findByMunicipality($municipality->id());
        self::assertCount(2, $logs);
        self::assertSame(AuditAction::MunicipalityContactChanged, $logs[0]->action());
        self::assertTrue($admin->id()->equals($logs[0]->actorId()));
        self::assertSame('Marie Martin', $logs[0]->previousValue()['contactName']);
        self::assertSame('+33472000000', $logs[0]->previousValue()['contactPhone']);
        self::assertSame('Paul Durand', $logs[0]->newValue()['contactName']);
        self::assertSame('+33472000001', $logs[0]->newValue()['contactPhone']);
        self::assertSame('69002', $logs[0]->newValue()['postalCode']);
        self::assertNull($logs[1]->previousValue()['contactName']);
    }

    public function testUnchangedContactWritesNoAuditEntry(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($root, $municipality);
        $this->manager->updateContact($municipality, 'Marie', null, null, null, null, null, $root);

        $this->manager->updateContact($municipality, 'Marie', null, null, null, null, null, $root);

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    public function testRefusedContactChangeLeavesNoTraceAndKeepsTheEntityManagerUsable(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($root, $municipality);

        try {
            $this->manager->updateContact($municipality, null, null, null, null, '69001', null, $root);
            self::fail('Code postal sans ville : refus attendu.');
        } catch (DomainRuleViolation) {
        }

        self::assertTrue($this->em->isOpen());
        self::assertNull($municipality->postalCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
        $this->manager->updateContact($municipality, 'Marie', null, null, null, null, null, $root);
        self::assertSame('Marie', $municipality->contactName());
    }

    public function testStatusChangeAuditsTheRefreshedPreviousValue(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($municipality);
        // Un autre processus a déjà suspendu la commune ; l'objet en mémoire est périmé (ACTIVE).
        $this->em->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$municipality->id()->toBinary()]);

        $this->manager->changeStatus($municipality, MunicipalityStatus::Inactive, null);

        $this->em->clear();
        $log = $this->service(AuditLogRepository::class)->findByMunicipality($municipality->id())[0];
        self::assertSame(['status' => 'SUSPENDED'], $log->previousValue());
        self::assertSame(['status' => 'INACTIVE'], $log->newValue());
    }
}
