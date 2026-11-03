<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventType;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Time\Occurrence;
use App\Event\Domain\Validity\ChoiceField;
use App\Event\Domain\Validity\DstChoice;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-event-schema')]
final class EventPersistenceTest extends EventSchemaTestCase
{
    /**
     * @return array{Event, EventParticipation, EventZone}
     */
    private function persistGraph(): array
    {
        $organizer = MunicipalityBuilder::aMunicipality()->build();
        $creator = UserBuilder::aUser()->build();
        $type = F::type();
        $event = F::draft($organizer, $creator, $type);
        $participation = F::organizerParticipation($event);
        $zone = F::readyZone($participation);
        $this->save($organizer, $creator, $type, $event, $participation, $zone->createdBy(), $zone);

        return [$event, $participation, $zone];
    }

    public function testGraphIsPersistedAndReloaded(): void
    {
        [$event, $participation, $zone] = $this->persistGraph();
        $ids = [$event->id(), $participation->id(), $zone->id()];
        $this->em->clear();

        $reloaded = $this->em->find(Event::class, $ids[0]);
        self::assertNotNull($reloaded);
        self::assertSame(EventStatus::Draft, $reloaded->status());
        self::assertSame($event->publicReference()->value, $reloaded->publicReference()->value);
        self::assertSame('Europe/Paris', $reloaded->timezone()->identifier());
        self::assertEquals($event->startsAt(), $reloaded->startsAt());
        self::assertSame($event->organizerMunicipality()->id()->toRfc4122(), $reloaded->organizerMunicipality()->id()->toRfc4122());

        $reloadedParticipation = $this->em->find(EventParticipation::class, $ids[1]);
        self::assertNotNull($reloadedParticipation);
        self::assertSame(ParticipationStatus::Active, $reloadedParticipation->status());
        self::assertTrue($reloadedParticipation->isOrganizer());

        $reloadedZone = $this->em->find(EventZone::class, $ids[2]);
        self::assertNotNull($reloadedZone);
        self::assertTrue($reloadedZone->isConfirmed());
        self::assertNull($reloadedZone->deletedAt());
    }

    public function testLastKnownSuspensionSurvivesReloadAndIsRenewedByANewSuspension(): void
    {
        [$event, $participation] = $this->persistGraph();
        $id = $event->id();
        $participationId = $participation->id();
        $first = new \DateTimeImmutable('2026-06-11 09:30:00', new \DateTimeZone('UTC'));
        $second = new \DateTimeImmutable('2026-06-12 14:00:00', new \DateTimeZone('UTC'));

        $event->activate(F::now());
        $event->suspend('Premier motif', $first);
        $participation->suspend('Participation suspendue', $first);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Event::class, $id) ?? self::fail('Événement absent.');
        self::assertSame(EventStatus::Suspended, $reloaded->status());
        self::assertSame('Premier motif', $reloaded->suspensionReason());
        self::assertEquals($first, $reloaded->suspendedAt());
        self::assertSame('2026-06-11 09:30:00', $this->em->getConnection()->fetchOne('SELECT suspended_at FROM event_event WHERE id = ?', [$id->toBinary()]), 'stocké en UTC');

        $reloaded->reactivate($first->modify('+1 hour'));
        $this->em->flush();
        $this->em->clear();
        $reactivated = $this->em->find(Event::class, $id) ?? self::fail('Événement absent.');
        self::assertSame(EventStatus::Active, $reactivated->status(), 'seul le statut fait autorité');
        self::assertSame('Premier motif', $reactivated->suspensionReason());
        self::assertEquals($first, $reactivated->suspendedAt());

        $reactivated->suspend('Second motif', $second);
        $this->em->flush();
        $this->em->clear();
        $renewed = $this->em->find(Event::class, $id) ?? self::fail('Événement absent.');
        self::assertSame('Second motif', $renewed->suspensionReason());
        self::assertEquals($second, $renewed->suspendedAt());

        // Aucune régression sur la participation : mêmes champs, indépendants de ceux de l'événement.
        $reloadedParticipation = $this->em->find(EventParticipation::class, $participationId) ?? self::fail('Participation absente.');
        self::assertSame(ParticipationStatus::Suspended, $reloadedParticipation->status());
        self::assertSame('Participation suspendue', $reloadedParticipation->suspensionReason());
        self::assertEquals($first, $reloadedParticipation->suspendedAt());
    }

    public function testAnEventNeverSuspendedHasNoSuspensionData(): void
    {
        [$event] = $this->persistGraph();
        $id = $event->id();
        $this->em->clear();

        $reloaded = $this->em->find(Event::class, $id) ?? self::fail('Événement absent.');
        self::assertNull($reloaded->suspendedAt());
        self::assertNull($reloaded->suspensionReason());
    }

    public function testDatesAreStoredInUtc(): void
    {
        [$event] = $this->persistGraph();

        $stored = $this->em->getConnection()->fetchOne('SELECT starts_at FROM event_event WHERE id = ?', [$event->id()->toBinary()]);

        self::assertSame('2026-06-10 08:00:00', $stored);
    }

    public function testSlotsKeepTheirLocalFieldsAndRoundTripToTheDomainObject(): void
    {
        [, , $zone] = $this->persistGraph();
        $slots = [
            ValiditySlot::continuous(T::date('2026-06-10'), T::time('08:00'), T::date('2026-06-12'), T::time('18:30')),
            ValiditySlot::punctual(T::date('2026-10-25'), T::time('02:30'), T::time('04:00'), new DstChoices([new DstChoice(T::date('2026-10-25'), ChoiceField::Start, Occurrence::Second)])),
            ValiditySlot::recurring(T::date('2026-06-01'), T::date('2026-06-30'), Weekdays::fromIsoDays([1, 3, 7]), T::time('22:00'), T::time('06:00')),
        ];
        $entities = array_map(fn (ValiditySlot $slot): EventZoneValidity => EventZoneValidity::fromSlot($zone, $slot, F::now()), $slots);
        $this->save(...$entities);
        $ids = array_map(static fn (EventZoneValidity $e) => $e->id(), $entities);
        $this->em->clear();

        foreach ($ids as $i => $id) {
            self::assertEquals($slots[$i], $this->em->find(EventZoneValidity::class, $id)?->toSlot());
        }
        $raw = $this->em->getConnection()->fetchAssociative('SELECT start_time, end_time, dst_choices FROM event_zone_validity WHERE id = ?', [$ids[1]->toBinary()]);
        self::assertSame('02:30:00', $raw['start_time'], 'heure locale, jamais convertie en UTC');
        self::assertSame([['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']], json_decode((string) $raw['dst_choices'], true));
    }

    public function testOnlyOneParticipationPerEventAndMunicipality(): void
    {
        [$event] = $this->persistGraph();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $inviter = UserBuilder::aUser()->build();
        $this->save($municipality, $inviter, EventParticipation::invite($event, $municipality, $inviter, null, F::now()));

        // Même couple (événement, commune) par SQL direct : la contrainte d'unicité doit refuser.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO event_participation (id, status, created_at, updated_at, event_id, municipality_id) VALUES (?, ?, ?, ?, ?, ?)',
            [random_bytes(16), 'INVITED', '2026-06-01 10:00:00', '2026-06-01 10:00:00', $event->id()->toBinary(), $municipality->id()->toBinary()],
        );
    }

    public function testPublicReferenceIsUnique(): void
    {
        [$event] = $this->persistGraph();
        $clone = new Event(
            $event->publicReference(),
            $event->organizerMunicipality(),
            $event->type(),
            'Autre',
            $event->timezone(),
            $event->startsAt(),
            null,
            $event->createdBy(),
            F::now(),
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $this->save($clone);
    }

    public function testForeignKeysRestrictPhysicalDeletion(): void
    {
        [$event, $participation, $zone] = $this->persistGraph();
        $connection = $this->em->getConnection();

        foreach ([
            ['event_participation', $participation->id()],
            ['event_event', $event->id()],
            ['event_type', $event->type()->id()],
            ['municipality', $event->organizerMunicipality()->id()],
        ] as [$table, $id]) {
            try {
                $connection->executeStatement(sprintf('DELETE FROM %s WHERE id = ?', $table), [$id->toBinary()]);
                self::fail($table.' : une ligne référencée ne doit pas pouvoir être supprimée.');
            } catch (ForeignKeyConstraintViolationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventTypeCodeIsUnique(): void
    {
        $this->save(new EventType('fire', 'Incendie'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(new EventType('fire', 'Autre incendie'));
    }
}
