<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\TeamInvitationService;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\UserBuilder;

/**
 * Gestion d'équipe simultanée sur MySQL réel (processus parallèles, LOT 07.2) : le dernier administrateur survit toujours, une
 * invitation n'est jamais à la fois acceptée et révoquée, un administrateur rétrogradé ne conserve aucun pouvoir.
 */
final class TeamConcurrencyTest extends DatabaseTestCase
{
    private Municipality $commune;
    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, MunicipalityMembership> */
    private array $memberships = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($this->commune);
    }

    private function member(string $key, MunicipalityRole ...$roles): void
    {
        $this->users[$key] = UserBuilder::aUser()->build();
        $this->memberships[$key] = MembershipBuilder::aMembership($this->users[$key], $this->commune)->withRoles(...$roles)->build();
        $this->save($this->users[$key], $this->memberships[$key]);
    }

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

    private function id(string $key): string
    {
        return $this->users[$key]->id()->toRfc4122();
    }

    private function mid(string $key): string
    {
        return $this->memberships[$key]->id()->toRfc4122();
    }

    private function communeId(): string
    {
        return $this->commune->id()->toRfc4122();
    }

    private function effectiveAdmins(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE mm.municipality_id = ? AND mm.active = 1 AND u.active = 1 AND JSON_CONTAINS(mm.roles, '\"MUNICIPALITY_ADMIN\"')", [$this->commune->id()->toBinary()]);
    }

    public function testTwoAdministratorsRemovingEachOtherLeaveOne(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);

        $outcomes = $this->race([
            ['team-remove', $this->communeId(), $this->mid('b'), $this->id('a')],
            ['team-remove', $this->communeId(), $this->mid('a'), $this->id('b')],
        ]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(1, $this->effectiveAdmins(), 'la commune garde un administrateur');
    }

    public function testTwoAdministratorsDemotingEachOtherLeaveOne(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);

        $outcomes = $this->race([
            ['team-roles', $this->communeId(), $this->mid('b'), 'INSTRUCTOR', $this->id('a')],
            ['team-roles', $this->communeId(), $this->mid('a'), 'INSTRUCTOR', $this->id('b')],
        ]);

        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(1, $this->effectiveAdmins());
    }

    public function testThreeAdministratorsRemovingTwoAtOnceNeverEmptyTheCommune(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);
        $this->member('c', MunicipalityRole::MunicipalityAdmin);

        $this->race([
            ['team-remove', $this->communeId(), $this->mid('b'), $this->id('a')],
            ['team-remove', $this->communeId(), $this->mid('c'), $this->id('a')],
            ['team-remove', $this->communeId(), $this->mid('a'), $this->id('c')],
        ]);

        self::assertGreaterThanOrEqual(1, $this->effectiveAdmins());
    }

    public function testADemotedAdministratorCannotActWhileBeingDemoted(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);
        $this->member('agent', MunicipalityRole::Instructor);

        $this->race([
            ['team-roles', $this->communeId(), $this->mid('b'), 'INSTRUCTOR', $this->id('a')],
            ['team-remove', $this->communeId(), $this->mid('agent'), $this->id('b')],
        ]);

        // Quel que soit l'ordre, l'état final est cohérent : si b a été rétrogradé AVANT d'agir, l'agent est resté ; sinon il a été retiré
        // par un administrateur alors en fonction. Dans tous les cas un administrateur subsiste.
        self::assertGreaterThanOrEqual(1, $this->effectiveAdmins());
        self::assertSame(['INSTRUCTOR'], (array) json_decode((string) $this->db()->fetchOne('SELECT roles FROM municipality_membership WHERE id = ?', [$this->memberships['b']->id()->toBinary()]), true));
    }

    public function testRoleChangeAndRemovalOfTheSameMembershipStayCoherent(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);
        $this->member('agent', MunicipalityRole::Instructor);

        $outcomes = $this->race([
            ['team-roles', $this->communeId(), $this->mid('agent'), 'EVENT_MANAGER', $this->id('a')],
            ['team-remove', $this->communeId(), $this->mid('agent'), $this->id('b')],
        ]);

        self::assertGreaterThanOrEqual(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT active FROM municipality_membership WHERE id = ?', [$this->memberships['agent']->id()->toBinary()]), 'le retrait l’emporte toujours à la fin');
    }

    public function testTwoSimultaneousInvitationsForTheSameAddressLeaveOneUsableLink(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $this->member('b', MunicipalityRole::MunicipalityAdmin);

        $this->race([
            ['team-invite', $this->communeId(), 'agent@mairie.example', 'INSTRUCTOR', $this->id('a')],
            ['team-invite', $this->communeId(), 'agent@mairie.example', 'EVENT_MANAGER', $this->id('b')],
        ]);

        $usable = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation WHERE consumed_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        self::assertSame(1, $usable, 'un seul lien utilisable par (commune, adresse)');
    }

    public function testAcceptanceAndRevocationOfATeamInvitationNeverBothSucceed(): void
    {
        $this->member('a', MunicipalityRole::MunicipalityAdmin);
        $invited = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $this->save($invited);
        $mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $mails);
        $issued = self::getContainer()->get(TeamInvitationService::class)->issue($this->commune, 'agent@mairie.example', [MunicipalityRole::Instructor], $this->users['a']);

        $outcomes = $this->race([
            ['accept', $mails->lastToken(), $invited->id()->toRfc4122()],
            ['team-revoke', $this->communeId(), $issued->invitation->id()->toRfc4122(), $this->id('a')],
        ]);

        $row = $this->db()->fetchAssociative('SELECT consumed_at, revoked_at FROM municipality_admin_invitation');
        $joined = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE user_id = ?', [$invited->id()->toBinary()]);
        self::assertSame(1, $this->ok($outcomes), json_encode($outcomes));
        self::assertFalse(null !== $row['consumed_at'] && null !== $row['revoked_at']);
        self::assertSame(null !== $row['consumed_at'] ? 1 : 0, $joined, 'rattaché si et seulement si acceptée');
    }
}
