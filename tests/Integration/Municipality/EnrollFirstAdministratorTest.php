<?php

declare(strict_types=1);

namespace App\Tests\Integration\Municipality;

use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityRegistrar;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\UserBuilder;

/**
 * Inscription du premier administrateur (lien d'invitation) et inscription d'une commune du référentiel (MySQL de test).
 */
final class EnrollFirstAdministratorTest extends DatabaseTestCase
{
    private MembershipManager $manager;
    private Municipality $commune;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(MembershipManager::class);
        $this->commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($this->commune);
    }

    private function user(string $email = 'directrice@mairie.example'): \App\Identity\Domain\User
    {
        $user = UserBuilder::aUser()->withEmail($email)->build();
        $this->save($user);

        return $user;
    }

    /** @return list<string> */
    private function rolesOf(\App\Identity\Domain\User $user): array
    {
        return (array) json_decode((string) $this->em->getConnection()->fetchOne('SELECT roles FROM municipality_membership WHERE user_id = ?', [$user->id()->toBinary()]), true);
    }

    public function testTheFirstAdministratorGetsTheAdministratorRoleOnlyAndTheAuditSaysWhy(): void
    {
        $user = $this->user();

        $membership = $this->manager->enrollFirstAdministrator($user, $this->commune, static fn (): bool => true);

        self::assertTrue($membership->isActive());
        self::assertSame(['MUNICIPALITY_ADMIN'], $this->rolesOf($user), 'jamais gestionnaire ni instructeur');
        $audit = (array) json_decode((string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'MEMBERSHIP_CREATED' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame('INVITATION_LINK', $audit['origin']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'));
    }

    public function testACommuneThatAlreadyHasAnAdministratorIsRefusedAndNothingIsWritten(): void
    {
        $this->save(MembershipBuilder::aMembership($this->user('legitime@mairie.example'), $this->commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $other = $this->user();
        $consumed = false;

        try {
            $this->manager->enrollFirstAdministrator($other, $this->commune, static function () use (&$consumed): bool {
                $consumed = true;

                return true;
            });
            self::fail('refus attendu');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('dispose déjà d\'un administrateur', $e->getMessage());
        }

        self::assertFalse($consumed, 'le lien n’est même pas consommé');
        self::assertSame([], $this->rolesOf($other));
    }

    public function testAnInactiveAdministratorDoesNotCountAsAnAdministrator(): void
    {
        $gone = $this->user('partie@mairie.example');
        $this->save(MembershipBuilder::aMembership($gone, $this->commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->inactive()->build());

        $this->manager->enrollFirstAdministrator($this->user(), $this->commune, static fn (): bool => true);

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testOnlyAnActiveCommuneAndAnActiveAccountMayEnroll(): void
    {
        $suspended = MunicipalityBuilder::aMunicipality()->withStatus(MunicipalityStatus::Suspended)->build();
        $this->save($suspended);
        $inactiveUser = UserBuilder::aUser()->inactive()->build();
        $this->save($inactiveUser);

        foreach ([[$this->user(), $suspended], [$inactiveUser, $this->commune]] as [$user, $commune]) {
            try {
                $this->manager->enrollFirstAdministrator($user, $commune, static fn (): bool => true);
                self::fail('refus attendu');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testAnAccountAlreadyAttachedKeepsItsRolesAndAnInactiveMembershipIsReactivatedAsAdministratorOnly(): void
    {
        $attached = $this->user('rattache@mairie.example');
        $this->save(MembershipBuilder::aMembership($attached, $this->commune)->withRoles(MunicipalityRole::Instructor)->build());
        try {
            $this->manager->enrollFirstAdministrator($attached, $this->commune, static fn (): bool => true);
            self::fail('refus attendu');
        } catch (OperationNotAllowed) {
            self::assertSame(['INSTRUCTOR'], $this->rolesOf($attached), 'rôles inchangés');
        }

        $returning = $this->user('revenue@mairie.example');
        $this->save(MembershipBuilder::aMembership($returning, $this->commune)->withRoles(MunicipalityRole::EventManager, MunicipalityRole::Instructor)->inactive()->build());
        $this->em->getConnection()->executeStatement('DELETE FROM municipality_membership WHERE user_id = ?', [$attached->id()->toBinary()]);

        $this->manager->enrollFirstAdministrator($returning, $this->commune, static fn (): bool => true);

        self::assertSame(['MUNICIPALITY_ADMIN'], $this->rolesOf($returning), 'réactivée avec le seul rôle administrateur');
    }

    public function testAConsumedLinkMeansNothingIsWritten(): void
    {
        $user = $this->user();

        try {
            $this->manager->enrollFirstAdministrator($user, $this->commune, static fn (): bool => false);
            self::fail('refus attendu');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('plus utilisable', $e->getMessage());
        }

        self::assertSame([], $this->rolesOf($user));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'MEMBERSHIP_CREATED'"));
    }

    public function testTwoSimultaneousEnrollmentsOfTheSameCommuneOnlyOneSucceeds(): void
    {
        $a = $this->user('a@mairie.example');
        $b = $this->user('b@mairie.example');

        $results = ParallelPhp::run('enroll-administrator.php', [
            [$a->id()->toRfc4122(), $this->commune->id()->toRfc4122()],
            [$b->id()->toRfc4122(), $this->commune->id()->toRfc4122()],
        ]);

        $outcomes = array_map(static fn (array $r): array => (array) json_decode($r['stdout'], true), $results);
        self::assertSame(1, \count(array_filter($outcomes, static fn (array $o): bool => true === ($o['ok'] ?? null))), json_encode($outcomes));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership'), 'un seul administrateur, jamais deux');
    }

    // ── Inscription d'une commune du référentiel ─────────────────────────────

    private function referential(string $code = '33503', string $name = 'Saumos', bool $active = true): NationalCommune
    {
        $commune = new NationalCommune(new NationalCommuneRecord($code, $name, '33', '75', ['33680'], 1800), 'test', EventFixtures::now());
        if (!$active) {
            $commune->deactivate(EventFixtures::now());
        }
        $this->save($commune);

        return $commune;
    }

    public function testRegisteringAReferentialCommuneCopiesTheOfficialDataAndGrantsNothing(): void
    {
        $registrar = $this->service(MunicipalityRegistrar::class);
        $actor = $this->user('organisatrice@mairie.example');

        $municipality = $registrar->registerFromReferential($this->referential(), $actor);

        self::assertSame(['Saumos', '33503', 'ACTIVE'], [$municipality->name(), $municipality->inseeCode()->value, $municipality->status()->value]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE municipality_id = ?', [$municipality->id()->toBinary()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'MUNICIPALITY_REGISTERED_FROM_REFERENTIAL'"));
    }

    public function testRegisteringTwiceReturnsTheSameCommuneWithoutDuplicate(): void
    {
        $registrar = $this->service(MunicipalityRegistrar::class);
        $actor = $this->user('organisatrice@mairie.example');
        $commune = $this->referential();

        $first = $registrar->registerFromReferential($commune, $actor);
        $second = $registrar->registerFromReferential($commune, $actor);

        self::assertTrue($first->id()->equals($second->id()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM municipality WHERE insee_code = '33503'"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'MUNICIPALITY_REGISTERED_FROM_REFERENTIAL'"), 'une seule inscription auditée');
    }

    public function testADeactivatedReferentialCommuneCannotBeRegistered(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->service(MunicipalityRegistrar::class)->registerFromReferential($this->referential(active: false), $this->user('organisatrice@mairie.example'));
    }
}
