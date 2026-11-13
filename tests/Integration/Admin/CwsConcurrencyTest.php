<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\MunicipalityAdminInvitationService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\UserBuilder;

/**
 * Opérations CWS simultanées sur MySQL réel (processus parallèles, LOT 07.1) : invariants préservés sous concurrence.
 */
final class CwsConcurrencyTest extends DatabaseTestCase
{
    /**
     * @param list<list<string>> $calls
     *
     * @return list<array<string, mixed>>
     */
    private function race(array $calls): array
    {
        return array_map(static fn (array $r): array => (array) json_decode($r['stdout'], true), ParallelPhp::run('cws-race.php', $calls));
    }

    /**
     * @param list<array<string, mixed>> $outcomes
     */
    private function ok(array $outcomes): int
    {
        return \count(array_filter($outcomes, static fn (array $o): bool => true === ($o['ok'] ?? null)));
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    public function testTwoAcceptancesOfTheSameInvitationGiveOneAdministrator(): void
    {
        $mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $mails);
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $invited = UserBuilder::aUser()->withEmail('maire@mairie.example')->build();
        $this->save($cws, $commune, $invited);
        self::getContainer()->get(MunicipalityAdminInvitationService::class)->issue($commune, 'maire@mairie.example', $cws);
        $token = $mails->lastToken();

        $outcomes = $this->race([['accept', $token, $invited->id()->toRfc4122()], ['accept', $token, $invited->id()->toRfc4122()]]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'ADMIN_INVITATION_ACCEPTED'"), 'une seule acceptation journalisée');
    }

    public function testAcceptanceAndRevocationNeverBothSucceed(): void
    {
        $mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $mails);
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $invited = UserBuilder::aUser()->withEmail('maire@mairie.example')->build();
        $this->save($cws, $commune, $invited);
        $issued = self::getContainer()->get(MunicipalityAdminInvitationService::class)->issue($commune, 'maire@mairie.example', $cws);

        $outcomes = $this->race([['accept', $mails->lastToken(), $invited->id()->toRfc4122()], ['revoke', $issued->invitation->id()->toRfc4122(), $cws->id()->toRfc4122()]]);

        $memberships = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership');
        $row = $this->db()->fetchAssociative('SELECT consumed_at, revoked_at FROM municipality_admin_invitation');
        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertFalse(null !== $row['consumed_at'] && null !== $row['revoked_at'], 'jamais acceptée ET révoquée');
        self::assertSame(null !== $row['consumed_at'] ? 1 : 0, $memberships, 'le rattachement existe si et seulement si l’invitation est acceptée');
    }

    public function testResendAndAcceptanceLeaveAtMostOneUsableLinkAndNoDoubleAdministrator(): void
    {
        $mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $mails);
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $invited = UserBuilder::aUser()->withEmail('maire@mairie.example')->build();
        $this->save($cws, $commune, $invited);
        $issued = self::getContainer()->get(MunicipalityAdminInvitationService::class)->issue($commune, 'maire@mairie.example', $cws);

        $this->race([['accept', $mails->lastToken(), $invited->id()->toRfc4122()], ['resend', $issued->invitation->id()->toRfc4122(), $cws->id()->toRfc4122()], ['resend', $issued->invitation->id()->toRfc4122(), $cws->id()->toRfc4122()]]);

        $usable = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation WHERE consumed_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        self::assertLessThanOrEqual(1, $usable, 'au plus un lien utilisable');
        self::assertLessThanOrEqual(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testRemovingBothAdministratorsAtOnceNeverLeavesTheCommuneWithoutOne(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $a = UserBuilder::aUser()->build();
        $b = UserBuilder::aUser()->build();
        $mA = MembershipBuilder::aMembership($a, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $mB = MembershipBuilder::aMembership($b, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save($cws, $commune, $a, $b, $mA, $mB);

        $outcomes = $this->race([['remove', $mA->id()->toRfc4122(), $cws->id()->toRfc4122()], ['remove', $mB->id()->toRfc4122(), $cws->id()->toRfc4122()]]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE active = 1'), 'le dernier administrateur survit');
    }

    public function testDeactivatingBothAdministratorAccountsAtOnceKeepsOne(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $a = UserBuilder::aUser()->build();
        $b = UserBuilder::aUser()->build();
        $this->save($cws, $commune, $a, $b, MembershipBuilder::aMembership($a, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(), MembershipBuilder::aMembership($b, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        $outcomes = $this->race([['deactivate', $a->id()->toRfc4122(), $cws->id()->toRfc4122()], ['deactivate', $b->id()->toRfc4122(), $cws->id()->toRfc4122()]]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE active = 1'), 'CWS + un administrateur');
    }

    public function testTwoSimultaneousGrantsLeaveOneOpenAccess(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($cws, $commune);

        $outcomes = $this->race([['grant', $commune->id()->toRfc4122(), $cws->id()->toRfc4122()], ['grant', $commune->id()->toRfc4122(), $cws->id()->toRfc4122()]]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'));
    }

    public function testTwoSimultaneousOpeningsOfTheSameCommuneGiveOneCommune(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $this->save($cws, new NationalCommune(new NationalCommuneRecord('33503', 'Saumos', '33', '75', ['33680'], 1800), 'test', EventFixtures::now()));

        $outcomes = $this->race([['open', '33503', $cws->id()->toRfc4122()], ['open', '33503', $cws->id()->toRfc4122()]]);

        self::assertSame(2, $this->ok($outcomes), 'idempotent : les deux appels aboutissent '.json_encode($outcomes));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality WHERE insee_code = ?', ['33503']), 'jamais de doublon');
        self::assertLessThanOrEqual(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'MUNICIPALITY_OPENED_BY_CWS'"));
    }
}
