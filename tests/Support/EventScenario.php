<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\EventAccessPolicy;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\ZoneType;
use App\Identity\Domain\User;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\EntitlementKind;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;

/**
 * Scénario intercommunal de référence pour les tests de sécurité :
 *  - A organise l'événement ; B est invitée (puis acceptante) ; C est étrangère à l'événement ; D est invitée aussi.
 *  - Chaque commune a un administrateur ; A et B ont un gestionnaire d'événement et un instructeur ; un
 *    super-administrateur CWS n'a AUCUN membership.
 *  - Zones et créneaux : un jeu par commune participante (A, B).
 */
final class EventScenario
{
    public Municipality $a;
    public Municipality $b;
    public Municipality $c;
    public Municipality $d;
    public User $adminA;
    public User $managerA;
    public User $instructorA;
    public User $adminB;
    public User $managerB;
    public User $instructorB;
    public User $adminC;
    public User $adminD;
    public User $superAdmin;
    public Event $event;
    public EventParticipation $participationA;
    public EventParticipation $participationB;
    public EventParticipation $participationD;
    public EventZone $zoneA;
    public EventZone $zoneB;
    public EventZoneValidity $slotA;
    public EventZoneValidity $slotB;
    public InMemoryMembershipRepository $membershipRepository;
    public InMemoryEventParticipationRepository $participationRepository;
    public FakeEntitlementProvider $entitlements;

    /** @var list<object> toutes les entités, dans un ordre de persistance valide */
    public array $entities = [];

    public static function create(bool $bAccepted = true): self
    {
        $s = new self();
        $now = EventFixtures::now();
        $s->a = MunicipalityBuilder::aMunicipality()->build();
        $s->b = MunicipalityBuilder::aMunicipality()->build();
        $s->c = MunicipalityBuilder::aMunicipality()->build();
        $s->d = MunicipalityBuilder::aMunicipality()->build();
        $user = static fn (): User => UserBuilder::aUser()->build();
        [$s->adminA, $s->managerA, $s->instructorA, $s->adminB, $s->managerB, $s->instructorB, $s->adminC, $s->adminD] = [$user(), $user(), $user(), $user(), $user(), $user(), $user(), $user()];
        $s->superAdmin = UserBuilder::aUser()->superAdmin()->build();

        $s->membershipRepository = new InMemoryMembershipRepository();
        $memberships = [];
        foreach ([
            [$s->adminA, $s->a, Role::MunicipalityAdmin], [$s->managerA, $s->a, Role::EventManager], [$s->instructorA, $s->a, Role::Instructor],
            [$s->adminB, $s->b, Role::MunicipalityAdmin], [$s->managerB, $s->b, Role::EventManager], [$s->instructorB, $s->b, Role::Instructor],
            [$s->adminC, $s->c, Role::MunicipalityAdmin], [$s->adminD, $s->d, Role::MunicipalityAdmin],
        ] as [$u, $m, $role]) {
            $membership = MembershipBuilder::aMembership($u, $m)->withRoles($role)->build();
            $s->membershipRepository->add($membership);
            $memberships[] = $membership;
        }

        $s->event = EventFixtures::draft($s->a, $s->adminA);
        $s->participationA = EventParticipation::forOrganizer($s->event, $now);
        $s->participationB = EventParticipation::invite($s->event, $s->b, $s->adminA, null, $now);
        $s->participationD = EventParticipation::invite($s->event, $s->d, $s->adminA, null, $now);
        if ($bAccepted) {
            $s->participationB->accept($now);
        }
        $s->event->activate($now);

        $s->participationRepository = new InMemoryEventParticipationRepository();
        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            $s->participationRepository->add($participation);
        }

        $s->zoneA = EventZone::create($s->participationA, 'Zone A', ZoneType::Street, null, $s->adminA, $now);
        $s->slotA = EventZoneValidity::fromSlot($s->zoneA, ValiditySlot::punctual(
            TimeFactory::date('2026-06-12'),
            TimeFactory::time('09:00'),
            TimeFactory::time('17:00'),
        ), $now);
        // La zone de B : « Commune entière » proposée (possible même invitée) ; confirmée seulement si B a accepté.
        $s->zoneB = EventZone::proposeWholeMunicipality($s->participationB, $s->adminB, $now);
        $s->slotB = EventZoneValidity::fromSlot($s->zoneB, ValiditySlot::punctual(
            TimeFactory::date('2026-06-13'),
            TimeFactory::time('09:00'),
            TimeFactory::time('17:00'),
        ), $now);

        $s->entitlements = new FakeEntitlementProvider();
        $s->entitlements->grant($s->a, new Entitlement(EntitlementKind::CwsGrant));

        $s->entities = [
            $s->a, $s->b, $s->c, $s->d,
            $s->adminA, $s->managerA, $s->instructorA, $s->adminB, $s->managerB, $s->instructorB, $s->adminC, $s->adminD, $s->superAdmin,
            ...$memberships,
            $s->event->type(), $s->event, $s->participationA, $s->participationB, $s->participationD,
            $s->zoneA, $s->zoneB, $s->slotA, $s->slotB,
        ];

        return $s;
    }

    public function policy(): EventAccessPolicy
    {
        return new EventAccessPolicy(
            new MembershipAccessPolicy($this->membershipRepository),
            $this->membershipRepository,
            $this->participationRepository,
            new EventCreationPolicy($this->entitlements),
        );
    }
}
