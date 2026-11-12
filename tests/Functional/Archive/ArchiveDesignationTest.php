<?php

declare(strict_types=1);

namespace App\Tests\Functional\Archive;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Archive\Application\ArchiveDesignationService;
use App\Identity\Application\UserManager;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ArchiveScenarioTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Désignation des `EVENT_MANAGER` (LOT 05.6.3, option B) : qui désigne, qui peut l'être, unicité, révocation définitive, révocation EFFECTIVE à la perte du rôle, de
 * l'appartenance ou du compte, jamais de réactivation, recontrôle à chaque accès même si un crochet est contourné.
 */
final class ArchiveDesignationTest extends ArchiveScenarioTestCase
{
    private function service(): ArchiveDesignationService
    {
        return static::getContainer()->get(ArchiveDesignationService::class);
    }

    private function designateAs(\App\Identity\Domain\User $actor, string $municipalityId, \App\Identity\Domain\User $target): mixed
    {
        return $this->service()->designate($this->managed($actor), $municipalityId, $this->eventId(), $target->id()->toRfc4122());
    }

    /** @return array{string, ?string, string} état, cause et utilisateur de la dernière désignation */
    private function lastDesignation(): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT active_marker, revocation_cause, HEX(user_id) AS u FROM archive_manager_designation ORDER BY designated_at DESC, id DESC LIMIT 1') ?: self::fail('Aucune désignation.');

        return [null === $row['active_marker'] ? 'revoked' : 'active', $row['revocation_cause'], (string) $row['u']];
    }

    private function membershipOf(\App\Identity\Domain\User $user, string $municipalityId): \App\Municipality\Domain\MunicipalityMembership
    {
        $commune = $this->em()->find(Municipality::class, $municipalityId) ?? self::fail('Commune absente.');

        return static::getContainer()->get(MembershipRepository::class)->findOneByUserAndMunicipality($this->managed($user), $commune) ?? self::fail('Appartenance absente.');
    }

    // ------------------------------------------------------------------ qui désigne, qui peut l'être

    public function testOnlyTheMunicipalityAdministratorDesignatesAndTheTargetMustBeAnActiveEventManagerOfTheMunicipality(): void
    {
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->superAdmin, $this->organizerId]] as [$actor, $commune]) {
            try {
                $this->designateAs($actor, $commune, $this->manager);
                self::fail('Seul le MUNICIPALITY_ADMIN de la commune désigne.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([$this->instructor, $this->partnerManager, $this->outsider, $this->admin] as $badTarget) { // instructeur seul, gestionnaire d'une AUTRE commune, étranger, administrateur sans rôle de gestionnaire
            try {
                $this->designateAs($this->admin, $this->organizerId, $badTarget);
                self::fail('Cible invalide acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation'));

        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        self::assertSame(['active', null, strtoupper(str_replace('-', '', $this->manager->id()->toRfc4122()))], $this->lastDesignation());
        $this->expectException(DomainRuleViolation::class);
        $this->designateAs($this->admin, $this->organizerId, $this->manager); // une seule désignation active par (participation, utilisateur)
    }

    public function testAnEventManagerOfOneMunicipalityCannotBeDesignatedByTheAdministratorOfAnotherAndForgedIdsAreRefused(): void
    {
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        $designation = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_manager_designation'))->toRfc4122();

        try {
            $this->service()->revoke($this->managed($this->partnerAdmin), $this->partnerId, $designation); // désignation d'une AUTRE commune
            self::fail('Désignation d\'une autre commune révoquée.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->service()->designate($this->managed($this->admin), $this->organizerId, Uuid::v7()->toRfc4122(), $this->manager->id()->toRfc4122()); // événement inconnu
            self::fail('Événement forgé accepté.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('active', $this->lastDesignation()[0]);
    }

    public function testARevocationIsDefinitiveAndAnotherDesignationIsAnewAct(): void
    {
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        $first = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_manager_designation'))->toRfc4122();

        $this->service()->revoke($this->managed($this->admin), $this->organizerId, $first);
        self::assertSame(['revoked', 'ADMIN_REQUEST', strtoupper(str_replace('-', '', $this->manager->id()->toRfc4122()))], $this->lastDesignation());
        try {
            $this->service()->revoke($this->managed($this->admin), $this->organizerId, $first);
            self::fail('Une désignation révoquée ne se révoque pas deux fois.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }

        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation'), 'nouvel acte : nouvelle ligne, l\'ancienne reste révoquée');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation WHERE active_marker = 1'));
    }

    // ------------------------------------------------------------------ révocation effective

    public function testLosingTheEventManagerRoleRevokesTheDesignationAndGivingTheRoleBackNeverRestoresIt(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'accès tant que désigné');

        $membership = $this->membershipOf($this->manager, $this->organizerId);
        static::getContainer()->get(MembershipManager::class)->changeRoles($membership, [Role::Instructor], $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame(['revoked', 'ROLE_LOST', strtoupper(str_replace('-', '', $this->manager->id()->toRfc4122()))], $this->lastDesignation());

        static::getContainer()->get(MembershipManager::class)->changeRoles($this->membershipOf($this->manager, $this->organizerId), [Role::EventManager], $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame('revoked', $this->lastDesignation()[0], 'le retour du rôle ne rétablit PAS la désignation');
        self::assertNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'accès refusé tant qu\'un administrateur ne désigne pas à nouveau');
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier));
    }

    public function testDeactivatingTheMembershipOrTheAccountRevokesTheDesignationWithItsCause(): void
    {
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        static::getContainer()->get(MembershipManager::class)->deactivate($this->membershipOf($this->manager, $this->organizerId), $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame(['revoked', 'MEMBERSHIP_DEACTIVATED'], \array_slice($this->lastDesignation(), 0, 2));

        static::getContainer()->get(MembershipManager::class)->grant($this->managed($this->manager), $this->em()->find(Municipality::class, $this->organizerId) ?? self::fail(), [Role::EventManager], $this->managed($this->admin));
        $this->em()->clear();
        self::assertSame('revoked', $this->lastDesignation()[0], 'réactiver l\'appartenance ne rétablit pas la désignation');

        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        static::getContainer()->get(UserManager::class)->deactivate($this->managed($this->manager), null);
        $this->em()->clear();
        self::assertSame(['revoked', 'USER_DEACTIVATED'], \array_slice($this->lastDesignation(), 0, 2));
        static::getContainer()->get(UserManager::class)->activate($this->managed($this->manager), null);
        $this->em()->clear();
        self::assertSame('revoked', $this->lastDesignation()[0], 'réactiver le compte ne rétablit pas la désignation');
    }

    public function testTheAccessIsRecheckedAtEveryRequestEvenIfTheRevocationHookWasBypassed(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        self::assertNotNull($this->openDossier($this->manager, $this->organizerId, $dossier));

        // Contournement : le rôle est retiré directement en base (aucun crochet), la désignation reste « active » en base.
        $this->sql('UPDATE municipality_membership SET roles = :r WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['r' => '["INSTRUCTOR"]', 'u' => str_replace('-', '', $this->manager->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);

        self::assertSame('active', $this->lastDesignation()[0]);
        self::assertNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'recontrôle du rôle à chaque accès');
        $this->sql('UPDATE municipality_membership SET roles = :r, active = 0 WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['r' => '["EVENT_MANAGER"]', 'u' => str_replace('-', '', $this->manager->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);
        self::assertNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'appartenance désactivée en base : refus');
        $this->sql('UPDATE municipality_membership SET active = 1 WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['u' => str_replace('-', '', $this->manager->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);
        $this->sql('UPDATE identity_user SET active = 0 WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $this->manager->id()->toRfc4122())]);
        self::assertNull($this->openDossier($this->manager, $this->organizerId, $dossier), 'compte désactivé en base : refus');
        self::assertInstanceOf(ArchiveAccessPolicy::class, static::getContainer()->get(ArchiveAccessPolicy::class));
    }

    public function testDesignationsAreAuditedWithoutAnyPersonalValue(): void
    {
        $this->designateAs($this->admin, $this->organizerId, $this->manager);
        $designation = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM archive_manager_designation'))->toRfc4122();
        $this->service()->revoke($this->managed($this->admin), $this->organizerId, $designation);

        $rows = $this->em()->getConnection()->fetchFirstColumn('SELECT new_value FROM audit_log WHERE action IN (\'ARCHIVE_MANAGER_DESIGNATED\', \'ARCHIVE_MANAGER_REVOKED\') ORDER BY occurred_at, id');
        self::assertCount(2, $rows);
        foreach ($rows as $json) {
            foreach (['@', 'Martin', 'Camille'] as $personal) {
                self::assertStringNotContainsString($personal, (string) $json);
            }
        }
        self::assertStringContainsString('ADMIN_REQUEST', (string) $rows[1]);
    }
}
