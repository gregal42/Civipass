<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Application;

use App\Event\Application\AccessDenialReason as Why;
use App\Event\Application\EventPermission as P;
use App\Event\Domain\EventStatus;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventScenario;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventAccessPolicyTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-15 10:00:00', new \DateTimeZone('UTC'));
    }

    private static function expectDenied(\App\Event\Application\AccessDecision $decision, Why $reason, string $message = ''): void
    {
        self::assertFalse($decision->isAllowed(), $message);
        self::assertSame($reason, $decision->reason, $message);
    }

    // ── Rôles ────────────────────────────────────────────────────────────────

    public function testAdministratorAndManagerMayCreateAnEventForTheirMunicipality(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertTrue($policy->forNewEvent($s->adminA, $s->a, self::now())->isAllowed());
        self::assertTrue($policy->forNewEvent($s->managerA, $s->a, self::now())->isAllowed());
    }

    public function testInstructorCannotCreateAnEvent(): void
    {
        $s = EventScenario::create();

        self::expectDenied($s->policy()->forNewEvent($s->instructorA, $s->a, self::now()), Why::RoleNotAllowed);
    }

    public function testCwsSuperAdminHasNoImplicitMunicipalRight(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::expectDenied($policy->forNewEvent($s->superAdmin, $s->a, self::now()), Why::NoAccess);
        self::expectDenied($policy->forEvent($s->superAdmin, P::UpdateEvent, $s->event), Why::NoAccess);
        self::expectDenied($policy->forEvent($s->superAdmin, P::ViewEvent, $s->event), Why::NoAccess);
        self::expectDenied($policy->forEvent($s->superAdmin, P::CloseEvent, $s->event), Why::NoAccess, 'la clôture forcée relève de CWS_FORCE_CLOSE_EVENT, pas de la politique communale');
        self::expectDenied($policy->forZone($s->superAdmin, P::ModifyZone, $s->zoneA), Why::NoAccess);
        self::expectDenied($policy->forParticipation($s->superAdmin, P::AcceptInvitation, $s->participationB), Why::NoAccess);
    }

    public function testSuperAdminWithAMembershipOnlyHasThatMembershipsRights(): void
    {
        $s = EventScenario::create();
        $user = UserBuilder::aUser()->superAdmin()->build();
        $s->membershipRepository->add(\App\Tests\Support\MembershipBuilder::aMembership($user, $s->a)->withRoles(\App\Municipality\Domain\MunicipalityRole::Instructor)->build());

        self::expectDenied($s->policy()->forEvent($user, P::UpdateEvent, $s->event), Why::RoleNotAllowed);
        self::assertTrue($s->policy()->forEvent($user, P::ViewEvent, $s->event)->isAllowed());
        self::expectDenied($s->policy()->forEvent($user, P::UpdateEvent, F::draft($s->b)), Why::NoAccess);
    }

    /**
     * @return iterable<string, array{P, EventStatus}>
     */
    public static function eventManagement(): iterable
    {
        yield 'modifier' => [P::UpdateEvent, EventStatus::Active];
        yield 'activer' => [P::ActivateEvent, EventStatus::Draft];
        yield 'suspendre' => [P::SuspendEvent, EventStatus::Active];
        yield 'réactiver' => [P::ReactivateEvent, EventStatus::Suspended];
        yield 'clôturer' => [P::CloseEvent, EventStatus::Active];
        yield 'inviter' => [P::InviteMunicipality, EventStatus::Active];
    }

    private static function eventIn(EventScenario $s, EventStatus $status): void
    {
        $now = F::now();
        $event = $s->event;
        // Le scénario crée un événement actif : on le ramène à l'état voulu par les transitions du domaine.
        match ($status) {
            EventStatus::Active => null,
            EventStatus::Suspended => $event->suspend('Motif', $now),
            EventStatus::Closed => $event->close($now),
            EventStatus::Draft => (static function () use ($s): void {
                $s->event = F::draft($s->a, $s->adminA);
            })(),
        };
    }

    #[DataProvider('eventManagement')]
    public function testEventManagementIsReservedToTheOrganizerAdministratorsAndManagers(P $permission, EventStatus $state): void
    {
        $s = EventScenario::create();
        self::eventIn($s, $state);
        $policy = $s->policy();

        self::assertTrue($policy->forEvent($s->adminA, $permission, $s->event)->isAllowed(), 'administrateur organisateur');
        self::assertTrue($policy->forEvent($s->managerA, $permission, $s->event)->isAllowed(), 'gestionnaire organisateur');
        self::expectDenied($policy->forEvent($s->instructorA, $permission, $s->event), Why::RoleNotAllowed, 'agent instructeur');
        self::expectDenied($policy->forEvent($s->adminB, $permission, $s->event), Why::NoAccess, 'administrateur de la commune invitée');
        self::expectDenied($policy->forEvent($s->managerB, $permission, $s->event), Why::NoAccess, 'le gestionnaire d\'une autre commune n\'acquiert pas les pouvoirs de l\'organisateur');
        self::expectDenied($policy->forEvent($s->adminC, $permission, $s->event), Why::NoAccess, 'commune étrangère');
        self::expectDenied($policy->forEvent($s->adminD, $permission, $s->event), Why::NoAccess, 'autre invitée');
    }

    public function testInactiveUserAndInactiveMembershipHaveNoAccess(): void
    {
        $s = EventScenario::create();
        $inactiveUser = UserBuilder::aUser()->inactive()->build();
        $s->membershipRepository->add(\App\Tests\Support\MembershipBuilder::aMembership($inactiveUser, $s->a)->withRoles(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin)->build());
        $ghost = UserBuilder::aUser()->build();
        $s->membershipRepository->add(\App\Tests\Support\MembershipBuilder::aMembership($ghost, $s->a)->withRoles(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin)->inactive()->build());
        $policy = $s->policy();

        foreach ([$inactiveUser, $ghost] as $user) {
            self::expectDenied($policy->forEvent($user, P::ViewEvent, $s->event), Why::NoAccess);
            self::expectDenied($policy->forEvent($user, P::UpdateEvent, $s->event), Why::NoAccess);
            self::expectDenied($policy->forNewEvent($user, $s->a, self::now()), Why::NoAccess);
        }
    }

    // ── États de l'événement ─────────────────────────────────────────────────

    public function testClosedEventFreezesEveryManagementAction(): void
    {
        $s = EventScenario::create();
        $s->event->close(F::now());
        $policy = $s->policy();

        foreach ([P::UpdateEvent, P::ActivateEvent, P::SuspendEvent, P::ReactivateEvent, P::CloseEvent, P::InviteMunicipality] as $permission) {
            self::expectDenied($policy->forEvent($s->adminA, $permission, $s->event), Why::EventClosed, $permission->value);
        }
        self::assertTrue($policy->forEvent($s->adminA, P::ViewEvent, $s->event)->isAllowed(), 'la consultation reste possible');
    }

    public function testStateSpecificTransitions(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        // Événement actif : ni activation ni réactivation.
        self::expectDenied($policy->forEvent($s->adminA, P::ActivateEvent, $s->event), Why::InvalidEventState);
        self::expectDenied($policy->forEvent($s->adminA, P::ReactivateEvent, $s->event), Why::InvalidEventState);
        // Brouillon : ni suspension ni réactivation, mais clôture possible (DM-077).
        $draft = F::draft($s->a, $s->adminA);
        self::expectDenied($policy->forEvent($s->adminA, P::SuspendEvent, $draft), Why::InvalidEventState);
        self::expectDenied($policy->forEvent($s->adminA, P::ReactivateEvent, $draft), Why::InvalidEventState);
        self::assertTrue($policy->forEvent($s->adminA, P::CloseEvent, $draft)->isAllowed());
        self::assertTrue($policy->forEvent($s->adminA, P::ActivateEvent, $draft)->isAllowed());
        // Suspendu : réactivation et clôture.
        $s->event->suspend('Motif', F::now());
        self::assertTrue($policy->forEvent($s->adminA, P::ReactivateEvent, $s->event)->isAllowed());
        self::assertTrue($policy->forEvent($s->adminA, P::CloseEvent, $s->event)->isAllowed());
        self::expectDenied($policy->forEvent($s->adminA, P::SuspendEvent, $s->event), Why::InvalidEventState);
    }

    public function testSuspendedEventAddsNoExtraRestrictions(): void
    {
        $s = EventScenario::create();
        $s->event->suspend('Motif', F::now());
        $policy = $s->policy();

        self::assertTrue($policy->forEvent($s->adminA, P::UpdateEvent, $s->event)->isAllowed());
        self::assertTrue($policy->forEvent($s->adminA, P::InviteMunicipality, $s->event)->isAllowed());
        self::assertTrue($policy->forZone($s->adminA, P::ModifyZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forZone($s->adminB, P::ModifyZone, $s->zoneB)->isAllowed());
        self::assertTrue($policy->forParticipation($s->adminB, P::SuspendParticipation, $s->participationB)->isAllowed());
        self::assertTrue($policy->forSlot($s->adminA, P::DeleteZoneValiditySlot, $s->slotA)->isAllowed());
    }

    // ── Visibilité ───────────────────────────────────────────────────────────

    public function testEventsAreOnlyVisibleToTheOrganizerAndParticipants(): void
    {
        $s = EventScenario::create(bAccepted: false);
        $policy = $s->policy();

        foreach ([$s->adminA, $s->managerA, $s->instructorA, $s->adminB, $s->instructorB, $s->adminD] as $user) {
            self::assertTrue($policy->forEvent($user, P::ViewEvent, $s->event)->isAllowed());
        }
        self::expectDenied($policy->forEvent($s->adminC, P::ViewEvent, $s->event), Why::NoAccess, 'un événement n\'est pas public');
    }

    public function testSuspendedMunicipalityReadsButNeverWrites(): void
    {
        $s = EventScenario::create();
        $s->a->changeStatus(MunicipalityStatus::Suspended, F::now());
        $policy = $s->policy();

        self::assertTrue($policy->forEvent($s->adminA, P::ViewEvent, $s->event)->isAllowed());
        self::assertTrue($policy->forZone($s->adminA, P::ViewZone, $s->zoneA)->isAllowed());
        self::expectDenied($policy->forEvent($s->adminA, P::UpdateEvent, $s->event), Why::MunicipalityNotWritable);
        self::expectDenied($policy->forZone($s->adminA, P::ModifyZone, $s->zoneA), Why::MunicipalityNotWritable);
        self::expectDenied($policy->forNewEvent($s->adminA, $s->a, self::now()), Why::MunicipalityNotWritable);
    }

    public function testInactiveMunicipalityHasNoAccessAtAll(): void
    {
        $s = EventScenario::create();
        $s->a->changeStatus(MunicipalityStatus::Inactive, F::now());

        self::expectDenied($s->policy()->forEvent($s->adminA, P::ViewEvent, $s->event), Why::NoAccess);
        self::expectDenied($s->policy()->forEvent($s->adminA, P::UpdateEvent, $s->event), Why::NoAccess);
    }

    // ── Droits d'utilisation ────────────────────────────────────────────────

    /**
     * @return iterable<string, array{list<Entitlement>, bool}>
     */
    public static function entitlements(): iterable
    {
        $future = new \DateTimeImmutable('2026-12-31 00:00:00', new \DateTimeZone('UTC'));
        $past = new \DateTimeImmutable('2026-06-01 00:00:00', new \DateTimeZone('UTC'));
        yield 'abonnement actif' => [[new Entitlement(EntitlementKind::Subscription)], true];
        yield 'TRIAL valide' => [[new Entitlement(EntitlementKind::Trial, $future)], true];
        yield 'accès commercial CWS sans expiration' => [[new Entitlement(EntitlementKind::CwsGrant)], true];
        yield 'accès commercial CWS valide' => [[new Entitlement(EntitlementKind::CwsGrant, $future)], true];
        yield 'accès commercial CWS expiré' => [[new Entitlement(EntitlementKind::CwsGrant, $past)], false];
        yield 'TRIAL expiré' => [[new Entitlement(EntitlementKind::Trial, $past)], false];
        yield 'aucun droit' => [[], false];
        yield 'un droit expiré et un valide' => [[new Entitlement(EntitlementKind::Trial, $past), new Entitlement(EntitlementKind::Subscription)], true];
    }

    /**
     * @param list<Entitlement> $entitlements
     */
    #[DataProvider('entitlements')]
    public function testCreationNeedsAValidUsageRight(array $entitlements, bool $expected): void
    {
        $s = EventScenario::create();
        $s->entitlements->grant($s->a, ...$entitlements);

        $decision = $s->policy()->forNewEvent($s->adminA, $s->a, self::now());

        self::assertSame($expected, $decision->isAllowed());
        if (!$expected) {
            self::assertSame(Why::NoUsageRight, $decision->reason);
        }
    }

    public function testMunicipalityWithoutPaidSubscriptionCreatesWithAValidCommercialAccess(): void
    {
        $s = EventScenario::create();

        self::assertTrue($s->policy()->forNewEvent($s->adminA, $s->a, self::now())->isAllowed(), 'A n\'a qu\'un accès commercial CWS');
    }

    public function testUsageRightAloneNeverReplacesTheRoleOrTheAdministrativeStatus(): void
    {
        $s = EventScenario::create();
        $s->entitlements->grant($s->b, new Entitlement(EntitlementKind::Subscription));
        $s->entitlements->grant($s->c, new Entitlement(EntitlementKind::Subscription));
        $s->c->changeStatus(MunicipalityStatus::Suspended, F::now());

        self::expectDenied($s->policy()->forNewEvent($s->instructorB, $s->b, self::now()), Why::RoleNotAllowed);
        self::expectDenied($s->policy()->forNewEvent($s->adminC, $s->c, self::now()), Why::MunicipalityNotWritable);
        self::expectDenied($s->policy()->forNewEvent($s->adminB, $s->a, self::now()), Why::NoAccess, 'on ne crée pas pour le compte d\'une autre commune');
    }

    public function testExpiredOrRevokedUsageRightNeverBlocksManagingExistingEvents(): void
    {
        $s = EventScenario::create();
        $s->entitlements->grant($s->a); // plus aucun droit : expiré ou révoqué
        $policy = $s->policy();

        self::expectDenied($policy->forNewEvent($s->adminA, $s->a, self::now()), Why::NoUsageRight);
        foreach ([P::UpdateEvent, P::SuspendEvent, P::CloseEvent, P::InviteMunicipality] as $permission) {
            self::assertTrue($policy->forEvent($s->adminA, $permission, $s->event)->isAllowed(), $permission->value);
        }
        self::assertTrue($policy->forZone($s->adminA, P::ModifyZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forZone($s->adminA, P::ConfigureZoneValidity, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forParticipation($s->adminA, P::CreateZone, $s->participationA)->isAllowed());
    }

    public function testInvitedMunicipalityNeedsNoUsageRightToParticipate(): void
    {
        $s = EventScenario::create(bAccepted: false);

        self::assertTrue($s->policy()->forParticipation($s->adminB, P::AcceptInvitation, $s->participationB)->isAllowed(), 'B n\'a aucun droit d\'utilisation');
    }

    public function testPermissionAppliedToTheWrongSubjectIsAProgrammingError(): void
    {
        $s = EventScenario::create();

        $this->expectException(\LogicException::class);
        $s->policy()->forZone($s->adminA, P::UpdateEvent, $s->zoneA);
    }

    public function testDecisionsTranslateToUniformRefusals(): void
    {
        $s = EventScenario::create();

        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('Ressource inaccessible.');
        $s->policy()->forEvent($s->adminC, P::UpdateEvent, $s->event)->orThrow();
    }

    public function testAllowedDecisionDoesNotThrow(): void
    {
        $s = EventScenario::create();

        $s->policy()->forEvent($s->adminA, P::UpdateEvent, $s->event)->orThrow();
        self::assertTrue($s->policy()->forEvent($s->adminA, P::UpdateEvent, $s->event)->isAllowed());
    }
}
