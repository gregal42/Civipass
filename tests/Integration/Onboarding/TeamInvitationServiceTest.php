<?php

declare(strict_types=1);

namespace App\Tests\Integration\Onboarding;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Application\AdminInvitationEnrollment;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\MunicipalityAdminInvitationService;
use App\Onboarding\Application\TeamInvitationService;
use App\Onboarding\Domain\LinkStatus;
use App\Onboarding\Domain\SignupData;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\UserBuilder;

/**
 * Invitations de collaborateurs émises par un administrateur communal (LOT 07.2) : rôles, comptes existants et multi-communes,
 * refus neutres, périmètre strict, débit, audit.
 */
final class TeamInvitationServiceTest extends DatabaseTestCase
{
    private TeamInvitationService $service;
    private AdminInvitationEnrollment $enrollment;
    private RecordingAdminInvitationNotifier $mails;
    private Municipality $communeA;
    private Municipality $communeB;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $this->mails);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->service = $this->service(TeamInvitationService::class);
        $this->enrollment = $this->service(AdminInvitationEnrollment::class);
        $this->communeA = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->communeB = MunicipalityBuilder::aMunicipality()->withInseeCode('69123')->build();
        $this->adminA = UserBuilder::aUser()->withEmail('admin-a@mairie.example')->build();
        $this->adminB = UserBuilder::aUser()->withEmail('admin-b@mairie.example')->build();
        $this->save($this->communeA, $this->communeB, $this->adminA, $this->adminB,
            MembershipBuilder::aMembership($this->adminA, $this->communeA)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->adminB, $this->communeB)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
    }

    private function signup(): SignupData
    {
        return new SignupData('Paul', 'Durand', 'une phrase de passe longue');
    }

    private function resolve(string $token): \App\Onboarding\Application\AdminInvitationResolution
    {
        $this->em->clear();
        $this->refetch();

        return $this->enrollment->resolve($token);
    }

    private function refetch(): void
    {
        $this->communeA = $this->em->find(Municipality::class, $this->communeA->id()) ?? throw new \LogicException();
        $this->communeB = $this->em->find(Municipality::class, $this->communeB->id()) ?? throw new \LogicException();
        $this->adminA = $this->em->find(User::class, $this->adminA->id()) ?? throw new \LogicException();
        $this->adminB = $this->em->find(User::class, $this->adminB->id()) ?? throw new \LogicException();
    }

    /** @return list<string> */
    private function rolesOf(User $user, Municipality $commune): array
    {
        return (array) json_decode((string) $this->em->getConnection()->fetchOne('SELECT roles FROM municipality_membership WHERE user_id = ? AND municipality_id = ?', [$user->id()->toBinary(), $commune->id()->toBinary()]), true);
    }

    public function testAcceptingGivesExactlyTheInvitedRolesAndNothingBefore(): void
    {
        $issued = $this->service->issue($this->communeA, 'agent@mairie.example', [MunicipalityRole::Instructor, MunicipalityRole::EventManager], $this->adminA);

        self::assertTrue($issued->emailSent);
        self::assertSame('Prénom Nom', $this->mails->sent[0]['inviter'] ?? null, 'le message nomme l’invitant');
        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->mails->sent[0]['roles'], 'ordre canonique, sans doublon');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['agent@mairie.example']), 'aucun compte avant acceptation');

        $resolution = $this->resolve($this->mails->lastToken());
        [$user] = $this->enrollment->accept($resolution, null, $this->signup(), true);

        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->rolesOf($user, $this->communeA), 'exactement les rôles invités, jamais administrateur');
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'ADMIN_INVITATION_ACCEPTED'");
        self::assertStringContainsString('INSTRUCTOR', $audit);
    }

    public function testAnAdministratorMayInviteAnotherAdministratorAndRolesAreMandatory(): void
    {
        $this->service->issue($this->communeA, 'pair@mairie.example', [MunicipalityRole::MunicipalityAdmin], $this->adminA);
        self::assertSame(['MUNICIPALITY_ADMIN'], $this->mails->sent[0]['roles']);
        $this->expectException(DomainRuleViolation::class);
        $this->service->issue($this->communeA, 'vide@mairie.example', [], $this->adminA);
    }

    public function testAnExistingAccountMustLogInAndKeepsIndependentRolesPerCommune(): void
    {
        $agent = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $this->save($agent, MembershipBuilder::aMembership($agent, $this->communeB)->withRoles(MunicipalityRole::Instructor)->build());
        $this->service->issue($this->communeA, 'agent@mairie.example', [MunicipalityRole::EventManager], $this->adminA);
        $resolution = $this->resolve($this->mails->lastToken());
        $agent = $this->em->find(User::class, $agent->id()) ?? throw new \LogicException();

        self::assertSame('LOGIN_REQUIRED', $this->enrollment->stepFor($resolution, null)->value);
        $this->enrollment->accept($resolution, $agent, null, true);

        self::assertSame(['EVENT_MANAGER'], $this->rolesOf($agent, $this->communeA));
        self::assertSame(['INSTRUCTOR'], $this->rolesOf($agent, $this->communeB), 'l’autre commune est intacte');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['agent@mairie.example']), 'aucun doublon de compte');
    }

    public function testAnInactiveMembershipIsReactivatedWithTheInvitedRolesWithoutDuplicate(): void
    {
        $former = UserBuilder::aUser()->withEmail('ancien@mairie.example')->build();
        $membership = MembershipBuilder::aMembership($former, $this->communeA)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save($former, $membership);
        $this->em->getConnection()->executeStatement('UPDATE municipality_membership SET active = 0 WHERE id = ?', [$membership->id()->toBinary()]);
        $this->em->clear();
        $this->refetch();
        $this->service->issue($this->communeA, 'ancien@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        $resolution = $this->resolve($this->mails->lastToken());
        $former = $this->em->find(User::class, $former->id()) ?? throw new \LogicException();

        $this->enrollment->accept($resolution, $former, null, true);

        self::assertSame(['INSTRUCTOR'], $this->rolesOf($former, $this->communeA), 'rôles invités, pas les anciens');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE user_id = ?', [$former->id()->toBinary()]), 'même ligne réactivée');
        self::assertContains('MEMBERSHIP_ACTIVATED', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log'));
    }

    public function testRefusalsNeverRevealWhatAnAccountIsOutsideTheCommune(): void
    {
        $cws = UserBuilder::aUser()->withEmail('cws@civipass.example')->superAdmin()->build();
        $disabled = UserBuilder::aUser()->withEmail('desactive@example.test')->build();
        $this->save($cws, $disabled);
        $this->em->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$disabled->id()->toBinary()]);
        $this->em->clear();
        $this->refetch();
        $messages = [];
        foreach (['cws@civipass.example', 'desactive@example.test'] as $email) {
            try {
                $this->service->issue($this->communeA, $email, [MunicipalityRole::Instructor], $this->adminA);
                self::fail('refus attendu');
            } catch (DomainRuleViolation $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertSame($messages[0], $messages[1], 'compte CWS et compte désactivé : même message');
        self::assertStringNotContainsStringIgnoringCase('CWS', $messages[0]);
        self::assertSame([], $this->mails->sent);

        // Un compte INCONNU et un compte actif d'une AUTRE commune : aucune différence de réponse.
        $this->service->issue($this->communeA, 'inconnu@example.test', [MunicipalityRole::Instructor], $this->adminA);
        $this->service->issue($this->communeA, 'admin-b@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        self::assertCount(2, $this->mails->sent);
    }

    public function testAnExistingActiveMemberIsToldToEditHisRolesInstead(): void
    {
        $this->service->issue($this->communeA, 'ok@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        $this->enrollment->accept($this->resolve($this->mails->lastToken()), null, $this->signup(), true);
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('modifiez ses rôles');
        $this->service->issue($this->communeA, 'ok@mairie.example', [MunicipalityRole::EventManager], $this->adminA);
    }

    public function testOnlyAnEffectiveAdministratorOfTheCommuneMayInviteResendOrRevoke(): void
    {
        $manager = UserBuilder::aUser()->build();
        $instructor = UserBuilder::aUser()->build();
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $this->save($manager, $instructor, $cws,
            MembershipBuilder::aMembership($manager, $this->communeA)->withRoles(MunicipalityRole::EventManager)->build(),
            MembershipBuilder::aMembership($instructor, $this->communeA)->withRoles(MunicipalityRole::Instructor)->build());
        $issued = $this->service->issue($this->communeA, 'x@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        $refused = 0;
        foreach ([$manager, $instructor, $this->adminB, $cws] as $intruder) {
            foreach ([
                fn () => $this->service->issue($this->communeA, 'y@mairie.example', [MunicipalityRole::Instructor], $intruder),
                fn () => $this->service->resend($this->communeA, $issued->invitation, $intruder),
                fn () => $this->service->revoke($this->communeA, $issued->invitation, $intruder),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail('acteur non autorisé');
                } catch (OperationNotAllowed) {
                    ++$refused;
                }
            }
        }
        self::assertSame(12, $refused);
    }

    public function testAnInvitationOfAnotherCommuneIsNotReachableAndASuspendedCommuneIsReadOnly(): void
    {
        $foreign = $this->service->issue($this->communeB, 'z@mairie.example', [MunicipalityRole::Instructor], $this->adminB);
        try {
            $this->service->revoke($this->communeA, $foreign->invitation, $this->adminA);
            self::fail('invitation étrangère');
        } catch (OperationNotAllowed) {
        }
        try {
            $this->service->resend($this->communeA, $foreign->invitation, $this->adminA);
            self::fail('invitation étrangère');
        } catch (OperationNotAllowed) {
        }
        self::assertSame(LinkStatus::Usable, $this->resolve($this->mails->lastToken())->status, 'l’invitation étrangère est intacte');

        $suspended = MunicipalityBuilder::aMunicipality()->withInseeCode('33503')->withStatus(MunicipalityStatus::Suspended)->build();
        $admin = UserBuilder::aUser()->build();
        $this->save($suspended, $admin, MembershipBuilder::aMembership($admin, $suspended)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->expectException(OperationNotAllowed::class);
        $this->service->issue($suspended, 'n@mairie.example', [MunicipalityRole::Instructor], $admin);
    }

    public function testResendInvalidatesTheOldLinkKeepsRolesAndRevocationIsFinal(): void
    {
        $first = $this->service->issue($this->communeA, 'agent@mairie.example', [MunicipalityRole::EventManager], $this->adminA);
        $old = $this->mails->lastToken();
        $second = $this->service->resend($this->communeA, $first->invitation, $this->adminA);
        $new = $this->mails->lastToken();

        self::assertSame(LinkStatus::Replaced, $this->resolve($old)->status);
        self::assertSame(LinkStatus::Usable, $this->resolve($new)->status);
        self::assertSame(['EVENT_MANAGER'], $this->mails->sent[1]['roles'], 'les rôles suivent le renvoi');
        $this->service->revoke($this->communeA, $second->invitation, $this->adminA);
        self::assertSame(LinkStatus::Replaced, $this->resolve($new)->status);
        $this->expectException(DomainRuleViolation::class);
        $this->service->revoke($this->communeA, $second->invitation, $this->adminA);
    }

    public function testInvitationsAreRateLimitedPerAdministrator(): void
    {
        $refused = false;
        for ($i = 1; $i <= 12; ++$i) {
            try {
                $this->service->issue($this->communeA, "agent$i@mairie.example", [MunicipalityRole::Instructor], $this->adminA);
            } catch (DomainRuleViolation $e) {
                $refused = true;
                self::assertStringContainsString('Trop d', $e->getMessage());
                break;
            }
        }
        self::assertTrue($refused, 'le plafond de 10 invitations par heure et par administrateur s’applique');
        self::assertCount(10, $this->mails->sent);
    }

    public function testAuditHoldsRolesButNeitherAddressNorToken(): void
    {
        $this->service->issue($this->communeA, 'secret.adresse@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        $token = $this->mails->lastToken();
        $audit = implode("\n", array_map(static fn (array $r): string => $r['new_value'], $this->em->getConnection()->fetchAllAssociative('SELECT new_value FROM audit_log')));
        self::assertStringContainsString('INSTRUCTOR', $audit);
        self::assertStringNotContainsString($token, $audit);
        self::assertStringNotContainsStringIgnoringCase('secret.adresse', $audit);
    }

    public function testCwsCanNeitherResendNorRevokeAnOrdinaryAgentInvitation(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $this->save($cws);
        $issued = $this->service->issue($this->communeA, 'agent@mairie.example', [MunicipalityRole::Instructor], $this->adminA);
        $cwsService = $this->service(MunicipalityAdminInvitationService::class);
        $this->em->clear();
        $cws = $this->em->find(User::class, $cws->id()) ?? throw new \LogicException();
        $invitation = $this->em->find($issued->invitation::class, $issued->invitation->id()) ?? throw new \LogicException();
        foreach ([fn () => $cwsService->resend($invitation, $cws), fn () => $cwsService->revoke($invitation, $cws)] as $attempt) {
            try {
                $attempt();
                self::fail('hors du périmètre de CWS');
            } catch (OperationNotAllowed) {
            }
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation'));
    }
}
