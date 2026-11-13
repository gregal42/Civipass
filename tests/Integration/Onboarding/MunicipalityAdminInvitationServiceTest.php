<?php

declare(strict_types=1);

namespace App\Tests\Integration\Onboarding;

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Application\AdminInvitationEnrollment;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\MunicipalityAdminInvitationService;
use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\LinkStatus;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Onboarding\Domain\SignupData;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\UserBuilder;

/**
 * Invitations d'administrateur communal émises par CWS (LOT 07.1) : émission, renvoi, révocation, acceptation, séparation CWS /
 * communes, absence de droit avant acceptation, aucun secret dans l'audit.
 */
final class MunicipalityAdminInvitationServiceTest extends DatabaseTestCase
{
    private MunicipalityAdminInvitationService $service;
    private AdminInvitationEnrollment $enrollment;
    private RecordingAdminInvitationNotifier $mails;
    private User $cws;
    private Municipality $commune;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new RecordingAdminInvitationNotifier();
        self::getContainer()->set(AdminInvitationNotifier::class, $this->mails);
        $this->service = $this->service(MunicipalityAdminInvitationService::class);
        $this->enrollment = $this->service(AdminInvitationEnrollment::class);
        $this->cws = UserBuilder::aUser()->withEmail('cws@civipass.example')->superAdmin()->build();
        $this->commune = MunicipalityBuilder::aMunicipality()->build();
        $this->save($this->cws, $this->commune);
    }

    private function signup(): SignupData
    {
        return new SignupData('Claire', 'Martin', 'une phrase de passe longue');
    }

    private function resolve(string $token): \App\Onboarding\Application\AdminInvitationResolution
    {
        $this->em->clear(); // état modifié par SQL conditionnel : en production chaque requête repart d'un contexte neuf
        $this->cws = $this->em->find(User::class, $this->cws->id()) ?? throw new \LogicException();
        $this->commune = $this->em->find(Municipality::class, $this->commune->id()) ?? throw new \LogicException();

        return $this->enrollment->resolve($token);
    }

    private function accept(string $token, ?User $current = null, bool $attest = true): User
    {
        $resolution = $this->enrollment->resolve($token);

        return $this->enrollment->accept($resolution, $current, null === $current ? $this->signup() : null, $attest)[0];
    }

    private function adminCount(): int
    {
        return $this->service(\App\Municipality\Domain\MembershipRepository::class)->countEffectiveAdmins($this->commune);
    }

    public function testIssuingGivesNoRightAndNoGhostAdministratorAndLeaksNoSecret(): void
    {
        $result = $this->service->issue($this->commune, 'Maire@Mairie.example', $this->cws);

        self::assertTrue($result->emailSent);
        self::assertSame(InvitationDeliveryStatus::Sent, $result->invitation->deliveryStatus());
        self::assertSame(0, $this->adminCount(), 'une invitation en attente n’est pas un administrateur');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email LIKE ?', ['maire@%']), 'aucun compte avant acceptation');
        $token = $this->mails->lastToken();
        self::assertNotSame($token, $result->invitation->tokenHash());
        $audit = implode("\n", array_map(static fn (array $r): string => $r['new_value'].$r['action'], $this->em->getConnection()->fetchAllAssociative('SELECT action, new_value FROM audit_log')));
        self::assertStringNotContainsString($token, $audit);
        self::assertStringNotContainsString($result->invitation->tokenHash(), $audit);
        self::assertStringNotContainsStringIgnoringCase('mairie.example', $audit, 'l’adresse n’entre jamais dans l’audit');
        self::assertContains('ADMIN_INVITATION_ISSUED', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log'));
    }

    public function testASmtpFailureIsRecordedWithoutExposingAnything(): void
    {
        $this->mails->failing = true;
        $result = $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);

        self::assertFalse($result->emailSent);
        $this->em->clear();
        $this->cws = $this->em->find(User::class, $this->cws->id()) ?? throw new \LogicException();
        $stored = $this->service(MunicipalityAdminInvitationRepository::class)->find($result->invitation->id());
        self::assertSame(InvitationDeliveryStatus::Failed, $stored?->deliveryStatus());
        self::assertContains('ADMIN_INVITATION_EMAIL_FAILED', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log'));
        // « Renvoyer » une fois le transport rétabli.
        $this->mails->failing = false;
        self::assertTrue($this->service->resend($stored, $this->cws)->emailSent);
    }

    public function testResendInvalidatesTheOldTokenAndKeepsASingleUsableLink(): void
    {
        $first = $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        $oldToken = $this->mails->lastToken();
        $second = $this->service->resend($first->invitation, $this->cws);
        $newToken = $this->mails->lastToken();

        self::assertNotSame($oldToken, $newToken);
        self::assertSame(LinkStatus::Replaced, $this->resolve($oldToken)->status);
        self::assertSame(LinkStatus::Usable, $this->resolve($newToken)->status);
        self::assertSame(1, $this->service(MunicipalityAdminInvitationRepository::class)->countPending($this->commune->id(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertSame($first->invitation->id()->toRfc4122(), $second->invitation->parent()?->id()->toRfc4122());
    }

    public function testRevokedExpiredUnknownAndMalformedTokensGiveNothing(): void
    {
        $issued = $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        $token = $this->mails->lastToken();
        $this->service->revoke($issued->invitation, $this->cws);
        self::assertSame(LinkStatus::Replaced, $this->resolve($token)->status);
        self::assertSame(LinkStatus::Unknown, $this->enrollment->resolve('court')->status);
        self::assertSame(LinkStatus::Unknown, $this->enrollment->resolve(str_repeat('A', 43))->status);

        $this->service->issue($this->commune, 'autre@mairie.example', $this->cws);
        $this->em->getConnection()->executeStatement("UPDATE municipality_admin_invitation SET expires_at = '2020-01-01 00:00:00' WHERE revoked_at IS NULL");
        self::assertSame(LinkStatus::Expired, $this->resolve($this->mails->lastToken())->status);
    }

    public function testAcceptingCreatesTheAccountAndTheAdministratorRoleOnlyOnce(): void
    {
        $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        $token = $this->mails->lastToken();

        $user = $this->accept($token);

        self::assertSame('maire@mairie.example', $user->email());
        self::assertSame(1, $this->adminCount());
        self::assertSame(['MUNICIPALITY_ADMIN'], (array) json_decode((string) $this->em->getConnection()->fetchOne('SELECT roles FROM municipality_membership WHERE user_id = ?', [$user->id()->toBinary()]), true));
        self::assertSame(LinkStatus::Used, $this->resolve($token)->status);
        $accepted = $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'ADMIN_INVITATION_ACCEPTED'");
        self::assertStringContainsString('"officialHabilitationVerified": false', (string) $accepted);
        $this->expectException(OperationNotAllowed::class);
        $this->enrollment->accept($this->enrollment->resolve($token), null, $this->signup(), true);
    }

    public function testTheAttestationIsMandatoryAndNothingIsWrittenWithoutIt(): void
    {
        $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        try {
            $this->accept($this->mails->lastToken(), null, attest: false);
            self::fail('attestation obligatoire');
        } catch (DomainRuleViolation) {
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['maire@mairie.example']));
        self::assertSame(0, $this->adminCount());
    }

    public function testAnExistingAccountMustLogInWithTheInvitedAddressAndIsNeverDuplicated(): void
    {
        $existing = UserBuilder::aUser()->withEmail('maire@mairie.example')->build();
        $other = UserBuilder::aUser()->withEmail('intrus@example.test')->build();
        $this->save($existing, $other);
        $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        $token = $this->mails->lastToken();
        $resolution = $this->enrollment->resolve($token);

        self::assertSame('LOGIN_REQUIRED', $this->enrollment->stepFor($resolution, null)->value);
        self::assertSame('WRONG_ACCOUNT', $this->enrollment->stepFor($resolution, $other)->value);
        try {
            $this->enrollment->accept($resolution, $other, null, true);
            self::fail('autre compte refusé');
        } catch (OperationNotAllowed) {
        }
        self::assertSame(0, $this->adminCount());

        $this->accept($token, $existing);
        self::assertSame(1, $this->adminCount());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['maire@mairie.example']), 'aucun doublon');
    }

    public function testACwsAccountCanNeitherBeInvitedNorAcceptNorBeAttached(): void
    {
        try {
            $this->service->issue($this->commune, 'cws@civipass.example', $this->cws);
            self::fail('s’inviter soi-même est interdit');
        } catch (DomainRuleViolation) {
        }
        $other = UserBuilder::aUser()->withEmail('autre-cws@civipass.example')->superAdmin()->build();
        $this->save($other);
        try {
            $this->service->issue($this->commune, 'autre-cws@civipass.example', $this->cws);
            self::fail('un compte CWS n’administre pas une commune');
        } catch (DomainRuleViolation) {
        }
        // Compte promu super-administrateur APRÈS l'émission : l'acceptation est refusée par le service de rattachement.
        $later = UserBuilder::aUser()->withEmail('plus-tard@example.test')->build();
        $this->save($later);
        $this->service->issue($this->commune, 'plus-tard@example.test', $this->cws);
        $token = $this->mails->lastToken();
        $this->em->getConnection()->executeStatement('UPDATE identity_user SET super_admin = 1 WHERE id = ?', [$later->id()->toBinary()]);
        $this->em->refresh($later);
        try {
            $this->enrollment->accept($this->enrollment->resolve($token), $later, null, true);
            self::fail('rattachement d’un super-administrateur refusé');
        } catch (OperationNotAllowed) {
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testOnlyAnActiveSuperAdminMayIssueResendOrRevoke(): void
    {
        $agent = UserBuilder::aUser()->build();
        $this->save($agent);
        $issued = $this->service->issue($this->commune, 'maire@mairie.example', $this->cws);
        $refused = 0;
        foreach ([
            fn () => $this->service->issue($this->commune, 'x@mairie.example', $agent),
            fn () => $this->service->resend($issued->invitation, $agent),
            fn () => $this->service->revoke($issued->invitation, $agent),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('acteur non autorisé');
            } catch (OperationNotAllowed) {
                ++$refused;
            }
        }
        self::assertSame(3, $refused);
    }

    public function testAnInactiveCommuneCannotBeInvitedForNorAcceptedInto(): void
    {
        $inactive = MunicipalityBuilder::aMunicipality()->withStatus(MunicipalityStatus::Inactive)->build();
        $this->save($inactive);
        $this->expectException(OperationNotAllowed::class);
        $this->service->issue($inactive, 'maire@mairie.example', $this->cws);
    }

    public function testASuspendedCommuneStillAcceptsItsAdministratorAndAnExistingAdministratorDoesNotBlockReplacement(): void
    {
        $suspended = MunicipalityBuilder::aMunicipality()->withStatus(MunicipalityStatus::Suspended)->build();
        $old = UserBuilder::aUser()->build();
        $this->save($suspended, $old, MembershipBuilder::aMembership($old, $suspended)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->service->issue($suspended, 'remplacant@mairie.example', $this->cws);

        $new = $this->enrollment->accept($this->enrollment->resolve($this->mails->lastToken()), null, $this->signup(), true)[0];

        self::assertSame(2, $this->service(\App\Municipality\Domain\MembershipRepository::class)->countEffectiveAdmins($suspended));
        // Remplacement : l'ancien peut maintenant être retiré, jamais le dernier.
        $oldMembership = $this->service(\App\Municipality\Domain\MembershipRepository::class)->findOneByUserAndMunicipality($old, $suspended);
        $this->service(MembershipManager::class)->removeAdministratorByCws($oldMembership, $this->cws);
        $newMembership = $this->service(\App\Municipality\Domain\MembershipRepository::class)->findOneByUserAndMunicipality($new, $suspended);
        $this->expectException(OperationNotAllowed::class);
        $this->service(MembershipManager::class)->removeAdministratorByCws($newMembership, $this->cws);
    }

    public function testRemovingAnAdministratorKeepsHisOtherRolesAndCwsCannotTouchItself(): void
    {
        $a = UserBuilder::aUser()->build();
        $b = UserBuilder::aUser()->build();
        $this->save($a, $b);
        $mA = MembershipBuilder::aMembership($a, $this->commune)->withRoles(MunicipalityRole::MunicipalityAdmin, MunicipalityRole::Instructor)->build();
        $mB = MembershipBuilder::aMembership($b, $this->commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save($mA, $mB);
        $manager = $this->service(MembershipManager::class);

        $manager->removeAdministratorByCws($mA, $this->cws);

        self::assertTrue($mA->isActive());
        self::assertSame([MunicipalityRole::Instructor], $mA->roles(), 'les autres rôles sont conservés');
        $this->expectException(OperationNotAllowed::class);
        $manager->removeAdministratorByCws($mB, $this->cws); // dernier administrateur
    }
}
