<?php

declare(strict_types=1);

namespace App\Tests\Integration\Municipality;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;

/**
 * Habilitations d'une équipe communale (LOT 07.2) : modification des rôles et retrait par un administrateur, périmètre strict,
 * auto-modification interdite, dernier administrateur protégé, retrait d'une commune sans effet sur les autres.
 */
final class TeamManagementTest extends DatabaseTestCase
{
    private MembershipManager $manager;
    private Municipality $a;
    private Municipality $b;
    private User $admin;
    private User $peer;
    private User $agent;
    private MunicipalityMembership $mAdmin;
    private MunicipalityMembership $mPeer;
    private MunicipalityMembership $mAgent;
    private MunicipalityMembership $mAgentInB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->service(MembershipManager::class);
        $this->a = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->b = MunicipalityBuilder::aMunicipality()->withInseeCode('69123')->build();
        $this->admin = UserBuilder::aUser()->build();
        $this->peer = UserBuilder::aUser()->build();
        $this->agent = UserBuilder::aUser()->build();
        $this->mAdmin = MembershipBuilder::aMembership($this->admin, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->mPeer = MembershipBuilder::aMembership($this->peer, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->mAgent = MembershipBuilder::aMembership($this->agent, $this->a)->withRoles(MunicipalityRole::Instructor)->build();
        $this->mAgentInB = MembershipBuilder::aMembership($this->agent, $this->b)->withRoles(MunicipalityRole::Instructor, MunicipalityRole::EventManager)->build();
        $this->save($this->a, $this->b, $this->admin, $this->peer, $this->agent, $this->mAdmin, $this->mPeer, $this->mAgent, $this->mAgentInB);
    }

    private function reload(): void
    {
        $this->em->clear();
        foreach (['a' => Municipality::class, 'b' => Municipality::class] as $prop => $class) {
            $this->$prop = $this->em->find($class, $this->$prop->id()) ?? throw new \LogicException();
        }
        foreach (['admin', 'peer', 'agent'] as $prop) {
            $this->$prop = $this->em->find(User::class, $this->$prop->id()) ?? throw new \LogicException();
        }
        foreach (['mAdmin', 'mPeer', 'mAgent', 'mAgentInB'] as $prop) {
            $this->$prop = $this->em->find(MunicipalityMembership::class, $this->$prop->id()) ?? throw new \LogicException();
        }
    }

    /** @return list<string> */
    private function roles(MunicipalityMembership $m): array
    {
        return (array) json_decode((string) $this->em->getConnection()->fetchOne('SELECT roles FROM municipality_membership WHERE id = ?', [$m->id()->toBinary()]), true);
    }

    private function active(MunicipalityMembership $m): bool
    {
        return 1 === (int) $this->em->getConnection()->fetchOne('SELECT active FROM municipality_membership WHERE id = ?', [$m->id()->toBinary()]);
    }

    private function refused(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('opération refusée attendue');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAnAdministratorChangesRolesImmediatelyAndTheAuditTellsWho(): void
    {
        $this->manager->changeTeamRoles($this->a, $this->mAgent, [MunicipalityRole::Instructor, MunicipalityRole::EventManager], $this->admin);

        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->roles($this->mAgent));
        self::assertContains('MEMBERSHIP_ROLES_CHANGED', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log'));
    }

    public function testGrantingAdministrationCumulatesTheThreeRolesByDefaultButAnExistingAdministratorKeepsHisExplicitChoice(): void
    {
        // Un agent promu administrateur reçoit par défaut les trois rôles (DM-124).
        $this->manager->changeTeamRoles($this->a, $this->mAgent, [MunicipalityRole::MunicipalityAdmin], $this->admin);
        $this->reload();
        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR', 'MUNICIPALITY_ADMIN'], $this->roles($this->mAgent));

        // Un administrateur existant dont l'administrateur retire des rôles les voit retirés : le cumul n'est qu'un défaut.
        $this->manager->changeTeamRoles($this->a, $this->mPeer, [MunicipalityRole::MunicipalityAdmin, MunicipalityRole::EventManager], $this->admin);
        $this->reload();
        self::assertSame(['EVENT_MANAGER', 'MUNICIPALITY_ADMIN'], $this->roles($this->mPeer));

        // Sans administration, aucun rôle n'est ajouté.
        $this->manager->changeTeamRoles($this->a, $this->mAgent, [MunicipalityRole::Instructor], $this->admin);
        $this->reload();
        self::assertSame(['INSTRUCTOR'], $this->roles($this->mAgent));
    }

    public function testAnAdministratorMayDemoteAPeerButNeverHimselfNorTheLastAdministrator(): void
    {
        $this->refused(fn () => $this->manager->changeTeamRoles($this->a, $this->mAdmin, [MunicipalityRole::Instructor], $this->admin)); // lui-même
        $this->manager->changeTeamRoles($this->a, $this->mPeer, [MunicipalityRole::Instructor], $this->admin); // un pair : autorisé (A2)
        self::assertSame(['INSTRUCTOR'], $this->roles($this->mPeer));
        $this->reload();
        // Il ne reste que $admin : plus personne ne peut le rétrograder ni le retirer (le pair n'est plus administrateur).
        $this->refused(fn () => $this->manager->changeTeamRoles($this->a, $this->mAdmin, [MunicipalityRole::Instructor], $this->peer));
        $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mAdmin, $this->peer));
        self::assertSame(['MUNICIPALITY_ADMIN'], $this->roles($this->mAdmin));
        self::assertTrue($this->active($this->mAdmin));
    }

    public function testTheLastAdministratorCannotBeRemovedByAPeerWhoLostHisOwnRoleAndNeverByAnotherPath(): void
    {
        $this->manager->removeFromTeam($this->a, $this->mPeer, $this->admin); // un pair retiré : autorisé
        $this->reload();
        $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mAdmin, $this->admin)); // lui-même
        self::assertTrue($this->active($this->mAdmin));
        self::assertSame(1, $this->service(\App\Municipality\Domain\MembershipRepository::class)->countEffectiveAdmins($this->a));
    }

    public function testRemovalCutsOnlyThisCommuneAndKeepsTheAccountAndTheOtherCommunes(): void
    {
        $this->manager->removeFromTeam($this->a, $this->mAgent, $this->admin);

        self::assertFalse($this->active($this->mAgent));
        self::assertTrue($this->active($this->mAgentInB), 'les habilitations de l’autre commune sont indépendantes');
        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->roles($this->mAgentInB));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT active FROM identity_user WHERE id = ?', [$this->agent->id()->toBinary()]), 'le compte n’est pas désactivé');
        self::assertContains('MEMBERSHIP_DEACTIVATED', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log'));
    }

    public function testTheScopeIsStrictAMembershipOfAnotherCommuneIsUntouchable(): void
    {
        $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mAgentInB, $this->admin));
        $this->refused(fn () => $this->manager->changeTeamRoles($this->a, $this->mAgentInB, [MunicipalityRole::MunicipalityAdmin], $this->admin));
        $this->refused(fn () => $this->manager->removeFromTeam($this->b, $this->mAgent, $this->admin)); // commune de l'URL ≠ commune du rattachement
        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->roles($this->mAgentInB));
        self::assertTrue($this->active($this->mAgent));
    }

    public function testOnlyAdministratorsOfTheCommuneActAndNoOneEscalatesIndirectly(): void
    {
        $manager = UserBuilder::aUser()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $this->save($manager, $cws, MembershipBuilder::aMembership($manager, $this->a)->withRoles(MunicipalityRole::EventManager)->build());
        $this->reload();
        $manager = $this->em->find(User::class, $manager->id()) ?? throw new \LogicException();
        $cws = $this->em->find(User::class, $cws->id()) ?? throw new \LogicException();
        foreach ([$this->agent, $manager, $cws] as $intruder) { // instructeur, gestionnaire, super-administrateur sans rattachement
            $this->refused(fn () => $this->manager->changeTeamRoles($this->a, $this->mAgent, [MunicipalityRole::MunicipalityAdmin], $intruder));
            $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mPeer, $intruder));
        }
        self::assertSame(['INSTRUCTOR'], $this->roles($this->mAgent), 'aucune élévation indirecte');
        // Administrateur d'une AUTRE commune : aucun pouvoir ici.
        $otherAdmin = UserBuilder::aUser()->build();
        $this->save($otherAdmin, MembershipBuilder::aMembership($otherAdmin, $this->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mAgent, $this->em->find(User::class, $otherAdmin->id())));
    }

    public function testASuspendedCommuneIsReadOnlyForTheTeam(): void
    {
        $suspended = MunicipalityBuilder::aMunicipality()->withInseeCode('33503')->withStatus(MunicipalityStatus::Suspended)->build();
        $adm = UserBuilder::aUser()->build();
        $other = UserBuilder::aUser()->build();
        $mAdm = MembershipBuilder::aMembership($adm, $suspended)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $mOther = MembershipBuilder::aMembership($other, $suspended)->withRoles(MunicipalityRole::Instructor)->build();
        $this->save($suspended, $adm, $other, $mAdm, $mOther);
        $this->refused(fn () => $this->manager->removeFromTeam($suspended, $mOther, $adm));
        $this->refused(fn () => $this->manager->changeTeamRoles($suspended, $mOther, [MunicipalityRole::EventManager], $adm));
        self::assertTrue($this->active($mOther));
    }

    public function testAnAdministratorDemotedBySomeoneElseLosesAllPowerAtOnce(): void
    {
        $this->manager->changeTeamRoles($this->a, $this->mPeer, [MunicipalityRole::Instructor], $this->admin);
        $this->reload();
        // Le pair rétrogradé tente d'agir avec son ancien pouvoir : refusé immédiatement, d'après l'état courant.
        $this->refused(fn () => $this->manager->removeFromTeam($this->a, $this->mAgent, $this->peer));
        self::assertTrue($this->active($this->mAgent));
    }
}
