<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Application;

use App\Event\Application\AccessDenialReason as Why;
use App\Event\Application\EventPermission as P;
use App\Event\Application\ParticipantListVisibility as V;
use App\Event\Application\PermissionSubject;
use App\Event\Application\Read\EventListItem;
use App\Event\Application\Read\EventParticipationDetails;
use App\Event\Application\Read\EventParticipationSummary;
use App\Event\Application\Read\InvitationView;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventScenario;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

/**
 * U2, U3, U4 : visibilité intercommunale (DM-084). A organise, B est invitée/acceptante, C est étrangère, D est invitée.
 */
final class ParticipantVisibilityTest extends TestCase
{
    // ── U3 : niveaux de visibilité sur les participations ────────────────────

    public function testTheOrganizerSeesEverything(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        foreach ([$s->adminA, $s->managerA] as $user) {
            self::assertSame(V::Full, $policy->participantListVisibility($user, $s->a, $s->event));
        }
    }

    // ── U5 : instructeurs ────────────────────────────────────────────────────

    public function testTheInstructorOfTheOrganizerNeverSeesInternalManagementInformation(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertSame(V::Coordination, $policy->participantListVisibility($s->instructorA, $s->a, $s->event), 'ni motifs, ni invitations en attente d\'autres communes');
        self::assertFalse($policy->forEvent($s->instructorA, P::ViewParticipationDetails, $s->event)->isAllowed());
        foreach ([$s->adminA, $s->managerA] as $user) {
            self::assertTrue($policy->forEvent($user, P::ViewParticipationDetails, $s->event)->isAllowed());
        }
        self::assertSame(Why::NoAccess, $policy->forEvent($s->adminB, P::ViewParticipationDetails, $s->event)->reason, 'une participante n\'a jamais le détail de gestion');
        self::assertSame(Why::NoAccess, $policy->forEvent($s->superAdmin, P::ViewParticipationDetails, $s->event)->reason);
        // Un utilisateur cumulant instructeur et gestionnaire dans la commune organisatrice obtient le niveau de gestion.
        $both = UserBuilder::aUser()->build();
        $s->membershipRepository->add(MembershipBuilder::aMembership($both, $s->a)->withRoles(MunicipalityRole::Instructor, MunicipalityRole::EventManager)->build());
        self::assertSame(V::Full, $policy->participantListVisibility($both, $s->a, $s->event));
    }

    // ── U6 : historique après clôture ────────────────────────────────────────

    private static function closeEvent(EventScenario $s): void
    {
        $s->event->close(F::now());
        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            $participation->closeWithEvent(F::now());
        }
    }

    public function testAfterClosureCommunesThatActuallyParticipatedSeeTheHistory(): void
    {
        $s = EventScenario::create();
        self::closeEvent($s);
        $policy = $s->policy();

        self::assertSame(V::History, $policy->participantListVisibility($s->adminB, $s->b, $s->event), 'B avait accepté (activated_at)');
        self::assertSame(V::History, $policy->participantListVisibility($s->instructorB, $s->b, $s->event));
        self::assertSame(V::History, $policy->participantListVisibility($s->instructorA, $s->a, $s->event), 'instructeur de l\'organisatrice : historique, pas le détail');
        self::assertSame(V::Full, $policy->participantListVisibility($s->adminA, $s->a, $s->event), 'l\'organisatrice garde la visibilité complète');
    }

    public function testAnInvitationNeverAcceptedStaysInvisibleAndSeesOnlyItself(): void
    {
        $s = EventScenario::create();
        self::closeEvent($s);
        $policy = $s->policy();

        self::assertNull($s->participationD->activatedAt());
        self::assertSame(V::OwnInvitationOnly, $policy->participantListVisibility($s->adminD, $s->d, $s->event), 'D n\'a jamais accepté : pas d\'historique');
        self::assertSame(V::None, $policy->participantListVisibility($s->adminC, $s->c, $s->event));
    }

    public function testClosureFreezesEveryWriteForParticipantsButKeepsTheHistoryReadable(): void
    {
        $s = EventScenario::create();
        self::closeEvent($s);
        $policy = $s->policy();

        self::assertSame(Why::EventClosed, $policy->forZone($s->adminB, P::ModifyZone, $s->zoneB)->reason);
        self::assertFalse($policy->forParticipation($s->adminB, P::CreateZone, $s->participationB)->isAllowed());
        self::assertTrue($policy->forZone($s->adminB, P::ViewZone, $s->zoneA)->isAllowed(), 'zones consultables après clôture (U2)');
    }

    public function testAnAcceptedParticipantSeesTheCoordinationListWhateverItsState(): void
    {
        $active = EventScenario::create();
        self::assertSame(V::Coordination, $active->policy()->participantListVisibility($active->adminB, $active->b, $active->event));
        self::assertSame(V::Coordination, $active->policy()->participantListVisibility($active->instructorB, $active->b, $active->event));

        $suspended = EventScenario::create();
        $suspended->participationB->suspend('Motif', F::now());
        self::assertSame(V::Coordination, $suspended->policy()->participantListVisibility($suspended->adminB, $suspended->b, $suspended->event), 'SUSPENDED : visibilité conservée');

        $closed = EventScenario::create();
        $closed->participationB->close(F::now());
        self::assertSame(V::Coordination, $closed->policy()->participantListVisibility($closed->adminB, $closed->b, $closed->event), 'CLOSED : ancienne participante, liste de coordination');
    }

    public function testAnInvitedMunicipalitySeesOnlyItsOwnInvitation(): void
    {
        $s = EventScenario::create(bAccepted: false);

        self::assertSame(V::OwnInvitationOnly, $s->policy()->participantListVisibility($s->adminB, $s->b, $s->event));
        self::assertSame(V::OwnInvitationOnly, $s->policy()->participantListVisibility($s->adminD, $s->d, $s->event));
        self::assertSame(V::OwnInvitationOnly, $s->policy()->participantListVisibility($s->instructorB, $s->b, $s->event));
    }

    public function testAForeignMunicipalityOrUserSeesNothing(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertSame(V::None, $policy->participantListVisibility($s->adminC, $s->c, $s->event));
        self::assertSame(V::None, $policy->participantListVisibility($s->superAdmin, $s->a, $s->event), 'CWS : aucun membership, aucun droit');
        self::assertSame(V::None, $policy->participantListVisibility($s->adminC, $s->a, $s->event), 'contexte forgé : membership absent dans A');
        self::assertSame(V::None, $policy->participantListVisibility($s->adminB, $s->a, $s->event), 'B ne peut pas se faire passer pour l\'organisatrice');
        self::assertSame(V::None, $policy->participantListVisibility($s->adminA, $s->c, $s->event), 'ni A pour C');
    }

    public function testMunicipalityAdministrativeStatusApplies(): void
    {
        $suspended = EventScenario::create();
        $suspended->a->changeStatus(MunicipalityStatus::Suspended, F::now());
        self::assertSame(V::Full, $suspended->policy()->participantListVisibility($suspended->adminA, $suspended->a, $suspended->event), 'lecture seule autorisée');

        $inactive = EventScenario::create();
        $inactive->a->changeStatus(MunicipalityStatus::Inactive, F::now());
        self::assertSame(V::None, $inactive->policy()->participantListVisibility($inactive->adminA, $inactive->a, $inactive->event));

        $inactiveUser = EventScenario::create();
        $ghost = UserBuilder::aUser()->inactive()->build();
        $inactiveUser->membershipRepository->add(MembershipBuilder::aMembership($ghost, $inactiveUser->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        self::assertSame(V::None, $inactiveUser->policy()->participantListVisibility($ghost, $inactiveUser->a, $inactiveUser->event));
    }

    public function testPrivilegesNeverAddUpAcrossMunicipalities(): void
    {
        // Instructeur de l'organisatrice A, administrateur de B (participante) : chaque contexte a SON niveau.
        $s = EventScenario::create();
        $dual = UserBuilder::aUser()->build();
        $s->membershipRepository->add(MembershipBuilder::aMembership($dual, $s->a)->withRoles(MunicipalityRole::Instructor)->build());
        $s->membershipRepository->add(MembershipBuilder::aMembership($dual, $s->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $s->membershipRepository->add(MembershipBuilder::aMembership($dual, $s->c)->withRoles(MunicipalityRole::Instructor)->build());
        $policy = $s->policy();

        self::assertSame(V::Coordination, $policy->participantListVisibility($dual, $s->a, $s->event), 'instructeur de l\'organisatrice : liste limitée (U5)');
        self::assertSame(V::Coordination, $policy->participantListVisibility($dual, $s->b, $s->event), 'en tant que B, pas de visibilité de l\'organisatrice');
        self::assertSame(V::None, $policy->participantListVisibility($dual, $s->c, $s->event));
        // Rôles : administrateur de B, il ne gère rien chez A ; instructeur chez A, il ne gère rien non plus.
        self::assertSame(Why::RoleNotAllowed, $policy->forEvent($dual, P::UpdateEvent, $s->event)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forZone($dual, P::ModifyZone, $s->zoneA)->reason, 'son rôle chez A (instructeur) prime : son rôle d\'administrateur de B ne s\'y applique pas');
        self::assertTrue($policy->forZone($dual, P::ModifyZone, $s->zoneB)->isAllowed(), 'mais sa propre zone est gérable');
    }

    // ── U2 : zones intercommunales ───────────────────────────────────────────

    public function testZonesOfOthersAreReadableByAcceptedParticipantsInEveryAcceptedState(): void
    {
        foreach (['ACTIVE' => null, 'SUSPENDED' => 'suspend', 'CLOSED' => 'close'] as $label => $transition) {
            $s = EventScenario::create();
            match ($transition) {
                'suspend' => $s->participationB->suspend('Motif', F::now()),
                'close' => $s->participationB->close(F::now()),
                default => null,
            };

            self::assertTrue($s->policy()->forZone($s->adminB, P::ViewZone, $s->zoneA)->isAllowed(), 'participation '.$label);
            self::assertSame(Why::NoAccess, $s->policy()->forZone($s->adminB, P::ModifyZone, $s->zoneA)->reason, 'jamais d\'écriture sur la zone d\'autrui ('.$label.')');
        }
    }

    public function testZonesOfOthersAreHiddenFromInvitedAndForeignMunicipalities(): void
    {
        $s = EventScenario::create(bAccepted: false);

        self::assertSame(Why::NoAccess, $s->policy()->forZone($s->adminB, P::ViewZone, $s->zoneA)->reason, 'B invitée');
        self::assertSame(Why::NoAccess, $s->policy()->forZone($s->adminC, P::ViewZone, $s->zoneA)->reason, 'C étrangère');
        self::assertTrue($s->policy()->forZone($s->adminB, P::ViewZone, $s->zoneB)->isAllowed(), 'B voit sa propre zone proposée');
    }

    // ── U4 : agent instructeur ───────────────────────────────────────────────

    public function testInstructorsMayConsultWhatTheirMunicipalityMaySee(): void
    {
        $s = EventScenario::create();
        $policy = $s->policy();

        self::assertTrue($policy->forEvent($s->instructorA, P::ViewEvent, $s->event)->isAllowed());
        self::assertTrue($policy->forEvent($s->instructorB, P::ViewEvent, $s->event)->isAllowed());
        self::assertTrue($policy->forZone($s->instructorA, P::ViewZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forZone($s->instructorB, P::ViewZone, $s->zoneA)->isAllowed());
        self::assertTrue($policy->forParticipation($s->instructorB, P::ViewParticipation, $s->participationB)->isAllowed());
        // Pas de droit global : une commune étrangère reste fermée à ses agents.
        $instructorC = UserBuilder::aUser()->build();
        $s->membershipRepository->add(MembershipBuilder::aMembership($instructorC, $s->c)->withRoles(MunicipalityRole::Instructor)->build());
        self::assertSame(Why::NoAccess, $policy->forEvent($instructorC, P::ViewEvent, $s->event)->reason);
        self::assertSame(Why::NoAccess, $policy->forZone($instructorC, P::ViewZone, $s->zoneA)->reason);
    }

    public function testInstructorsCannotPerformAnyManagementOperation(): void
    {
        $s = EventScenario::create(bAccepted: false);
        $s->participationB->accept(F::now());
        $policy = $s->policy();
        $tested = 0;

        foreach ([[$s->instructorA, $s->a, $s->participationA, $s->zoneA, $s->slotA], [$s->instructorB, $s->b, $s->participationB, $s->zoneB, $s->slotB]] as [$instructor, $municipality, $participation, $zone, $slot]) {
            foreach (P::cases() as $permission) {
                if ($permission->isRead()) {
                    continue;
                }
                $decision = match ($permission->subject()) {
                    PermissionSubject::NewEvent => $policy->forNewEvent($instructor, $municipality, new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC'))),
                    PermissionSubject::Event => $policy->forEvent($instructor, $permission, $s->event),
                    PermissionSubject::Participation => $policy->forParticipation($instructor, $permission, $participation),
                    PermissionSubject::Zone => $policy->forZone($instructor, $permission, $zone),
                    PermissionSubject::ZoneValiditySlot => $policy->forSlot($instructor, $permission, $slot),
                };
                self::assertFalse($decision->isAllowed(), $permission->value);
                // Rôle insuffisant dans la commune propriétaire, ou aucun lien (événement d'une autre commune).
                self::assertContains($decision->reason, [Why::RoleNotAllowed, Why::NoAccess], $permission->value);
                ++$tested;
            }
        }
        self::assertSame(2 * 16, $tested, 'les 16 permissions de gestion (19 moins 3 lectures), pour les deux communes');
    }

    public function testEventManagersCannotTouchZonesNorSlotsNorAcceptInvitations(): void
    {
        $s = EventScenario::create(bAccepted: false);
        $policy = $s->policy();

        self::assertSame(Why::RoleNotAllowed, $policy->forParticipation($s->managerA, P::CreateZone, $s->participationA)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forZone($s->managerA, P::ModifyZone, $s->zoneA)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forZone($s->managerA, P::DeleteZone, $s->zoneA)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forZone($s->managerA, P::ConfigureZoneValidity, $s->zoneA)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forSlot($s->managerA, P::DeleteZoneValiditySlot, $s->slotA)->reason);
        self::assertSame(Why::RoleNotAllowed, $policy->forParticipation($s->managerB, P::AcceptInvitation, $s->participationB)->reason);
        // …mais gère l'événement, les invitations et sa participation (DM-057).
        self::assertTrue($policy->forEvent($s->managerA, P::InviteMunicipality, $s->event)->isAllowed());
    }

    // ── Projections : aucun champ interne ────────────────────────────────────

    public function testCoordinationProjectionContainsNoInternalField(): void
    {
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(EventParticipationSummary::class))->getProperties());

        self::assertSame(['municipalityId', 'municipalityName', 'status', 'organizer'], $properties);
        $forbidden = '/reason|motif|audit|invit|message|actor|operator|batch|user|agent|email|phone|commercial|access|entitlement|subscription|suspendedAt|closedAt|activatedAt/i';
        foreach ($properties as $property) {
            self::assertDoesNotMatchRegularExpression($forbidden, $property);
        }
    }

    public function testInvitedViewAndEventListCarryNoCommercialOrPersonalData(): void
    {
        $forbidden = '/audit|actor|operator|batch|user|agent|email|phone|commercial|entitlement|subscription|createdBy|invitedBy/i';
        foreach ([InvitationView::class, EventListItem::class, EventParticipationDetails::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                self::assertDoesNotMatchRegularExpression($forbidden, $property->getName(), $class);
            }
        }
    }

    public function testProjectionsAreImmutableValueObjectsWithoutDoctrineEntities(): void
    {
        foreach ([EventParticipationSummary::class, EventParticipationDetails::class, InvitationView::class, EventListItem::class] as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly(), $class);
            self::assertTrue($reflection->isFinal(), $class);
            foreach ($reflection->getProperties() as $property) {
                $type = (string) $property->getType();
                self::assertStringNotContainsString('App\\Event\\Domain\\Event', str_replace(['App\\Event\\Domain\\EventStatus'], '', $type), $class.'::'.$property->getName());
                self::assertStringNotContainsString('Municipality', $type, $class.'::'.$property->getName());
                self::assertStringNotContainsString('User', $type);
            }
        }
    }
}
