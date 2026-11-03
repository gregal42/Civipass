<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventType;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\PublicReference;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\ZoneType;
use App\Municipality\Domain\Municipality;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-event-schema')]
final class EventRepositoriesTest extends EventSchemaTestCase
{
    private Municipality $organizer;
    private Municipality $guest;
    private Municipality $stranger;
    private Event $event;
    private EventParticipation $organizerParticipation;
    private EventParticipation $guestParticipation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizer = MunicipalityBuilder::aMunicipality()->build();
        $this->guest = MunicipalityBuilder::aMunicipality()->build();
        $this->stranger = MunicipalityBuilder::aMunicipality()->build();
        $creator = UserBuilder::aUser()->build();
        $type = F::type();
        $this->event = F::draft($this->organizer, $creator, $type);
        $this->organizerParticipation = F::organizerParticipation($this->event);
        $this->guestParticipation = EventParticipation::invite($this->event, $this->guest, $creator, null, F::now());
        $this->save($this->organizer, $this->guest, $this->stranger, $creator, $type, $this->event, $this->organizerParticipation, $this->guestParticipation);
    }

    private function events(): EventRepository
    {
        return $this->service(EventRepository::class);
    }

    private function participations(): EventParticipationRepository
    {
        return $this->service(EventParticipationRepository::class);
    }

    private function zones(): EventZoneRepository
    {
        return $this->service(EventZoneRepository::class);
    }

    private function slots(): EventZoneValidityRepository
    {
        return $this->service(EventZoneValidityRepository::class);
    }

    public function testEventsAreOnlyVisibleToTheirOrganizerAndParticipants(): void
    {
        $id = $this->event->id();
        $this->em->clear();

        self::assertNotNull($this->events()->findAccessibleTo($id, $this->organizer->id()));
        self::assertNotNull($this->events()->findAccessibleTo($id, $this->guest->id()), 'une commune invitée voit l\'événement');
        self::assertNull($this->events()->findAccessibleTo($id, $this->stranger->id()), 'une commune étrangère ne voit rien, même avec un identifiant valide');
        self::assertCount(1, $this->events()->listForMunicipality($this->guest->id()));
        self::assertCount(0, $this->events()->listForMunicipality($this->stranger->id()));
    }

    public function testFindByPublicReferenceAndLock(): void
    {
        $this->em->clear();

        self::assertNotNull($this->events()->findByPublicReference($this->event->publicReference()));
        self::assertNull($this->events()->findByPublicReference(PublicReference::fromString('EVT-2099-999999')));
        $locked = $this->transactional(fn () => $this->events()->lock($this->event->id()));
        self::assertSame($this->event->id()->toRfc4122(), $locked?->id()->toRfc4122());
    }

    public function testParticipationsAreScopedToTheirMunicipality(): void
    {
        $id = $this->guestParticipation->id();
        $this->em->clear();

        self::assertNotNull($this->participations()->findForMunicipality($id, $this->guest->id()));
        self::assertNull($this->participations()->findForMunicipality($id, $this->organizer->id()), 'l\'organisatrice n\'atteint pas la participation d\'une autre commune');
        self::assertNull($this->participations()->findForMunicipality($id, $this->stranger->id()));
        self::assertNotNull($this->participations()->findByEventAndMunicipality($this->event->id(), $this->guest->id()));
        self::assertNull($this->participations()->findByEventAndMunicipality($this->event->id(), $this->stranger->id()));
        self::assertCount(2, $this->participations()->findByEvent($this->event->id()));
        self::assertNotNull($this->transactional(fn () => $this->participations()->lock($id)));
    }

    public function testZonesAreScopedAndBulkSelectionDetectsForeignIdentifiers(): void
    {
        $mine = F::zone($this->organizerParticipation);
        $second = EventZone::create($this->organizerParticipation, 'Autre zone', ZoneType::District, null, UserBuilder::aUser()->build(), F::now());
        $deleted = F::zone($this->organizerParticipation);
        $deleted->markDeleted(F::now());
        $this->guestParticipation->accept(F::now());
        $theirs = F::zone($this->guestParticipation);
        $this->save($mine->createdBy(), $second->createdBy(), $deleted->createdBy(), $theirs->createdBy(), $mine, $second, $deleted, $theirs);
        $ids = [$mine->id(), $second->id(), $theirs->id(), $deleted->id()];
        $this->em->clear();

        self::assertNotNull($this->zones()->findForMunicipality($ids[0], $this->organizer->id()));
        self::assertNull($this->zones()->findForMunicipality($ids[2], $this->organizer->id()), 'zone d\'une autre commune');
        self::assertCount(2, $this->zones()->findActiveByParticipation($this->organizerParticipation->id()), 'les zones supprimées sont exclues');

        // Opération groupée : le nombre trouvé doit égaler le nombre demandé, sinon refus en bloc.
        $found = $this->zones()->findByIdsInParticipation($ids, $this->organizerParticipation->id());
        self::assertCount(2, $found, 'zone étrangère et zone supprimée non retournées');
        self::assertNotSame(\count($ids), \count($found));
        self::assertSame([], $this->zones()->findByIdsInParticipation([], $this->organizerParticipation->id()));
    }

    public function testActivationGuardQuery(): void
    {
        $eventId = $this->event->id();
        $organizerAdmin = UserBuilder::aUser()->build();
        $this->save($organizerAdmin);
        $zone = EventZone::create($this->organizerParticipation, 'Zone', ZoneType::Street, null, $organizerAdmin, F::now());
        $this->save($zone);
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'validité non configurée');

        $zone->changeValidityMode(ValidityMode::SpecificPeriods, F::now());
        $this->em->flush();
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'périodes spécifiques sans créneau');

        $slot = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-12-01'), T::time('09:00'), T::time('10:00')), F::now());
        $this->save($slot);
        self::assertTrue($this->zones()->existsActivationReadyZone($eventId), 'un créneau futur suffit');

        $slot->markDeleted(F::now());
        $this->em->flush();
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'créneau supprimé exclu');

        $zone->changeValidityMode(ValidityMode::EventDuration, F::now());
        $this->em->flush();
        self::assertTrue($this->zones()->existsActivationReadyZone($eventId));

        $zone->suspend(F::now());
        $this->em->flush();
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'zone suspendue');
        $zone->reactivate(F::now());

        $zone->markDeleted(F::now());
        $this->em->flush();
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'zone supprimée');
    }

    public function testActivationGuardIgnoresUnconfirmedProposalsAndNonActiveParticipations(): void
    {
        $eventId = $this->event->id();
        $proposal = EventZone::proposeWholeMunicipality($this->organizerParticipation, UserBuilder::aUser()->build(), F::now());
        $this->save($proposal->createdBy(), $proposal);
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'Commune entière proposée mais non confirmée');

        $proposal->confirm(F::now());
        $proposal->changeValidityMode(ValidityMode::EventDuration, F::now());
        $this->em->flush();
        self::assertTrue($this->zones()->existsActivationReadyZone($eventId));

        $this->organizerParticipation->suspend('Motif', F::now());
        $this->em->flush();
        self::assertFalse($this->zones()->existsActivationReadyZone($eventId), 'participation suspendue (R7)');
    }

    public function testSlotsQueriesExcludeDeletedAndKeepHistory(): void
    {
        $zone = F::readyZone($this->organizerParticipation);
        $other = F::readyZone($this->organizerParticipation);
        $first = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-10'), T::time('09:00'), T::time('10:00')), F::now());
        $replaced = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-11'), T::time('09:00'), T::time('10:00')), F::now());
        $replaced->markDeleted(F::now());
        $replacement = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date('2026-06-12'), T::time('09:00'), T::time('10:00')), F::now());
        $foreign = EventZoneValidity::fromSlot($other, ValiditySlot::punctual(T::date('2026-06-13'), T::time('09:00'), T::time('10:00')), F::now());
        $this->save($zone->createdBy(), $other->createdBy(), $zone, $other, $first, $replaced, $replacement, $foreign);
        $zoneId = $zone->id();
        $otherId = $other->id();
        $this->em->clear();

        self::assertCount(2, $this->slots()->findActiveByZone($zoneId));
        self::assertCount(3, $this->slots()->findHistoryByZone($zoneId), 'l\'historique conserve le créneau supprimé');
        self::assertCount(3, $this->slots()->findActiveByZones([$zoneId, $otherId]));
        self::assertSame([], $this->slots()->findActiveByZones([]));
    }

    public function testEventTypesAreOrderedAndOnlyActiveOnesAreListed(): void
    {
        $this->save(new EventType('storm', 'Tempête', 30), new EventType('flood', 'Inondation', 20));
        $inactive = new EventType('works', 'Travaux', 10);
        $inactive->deactivate();
        $this->save($inactive);
        $repository = $this->service(EventTypeRepository::class);

        self::assertSame(['fire', 'flood', 'storm'], array_map(static fn (EventType $t): string => $t->code(), $repository->findActive()), 'fire (0) créé par setUp, works inactif exclu');
        self::assertNotNull($repository->findByCode('works'));
        self::assertNull($repository->findByCode('unknown'));
    }
}
