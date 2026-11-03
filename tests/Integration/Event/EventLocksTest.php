<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Support\EventLocker;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventScenario;
use App\Tests\Support\EventSchemaTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Verrous sur MySQL réel, deux connexions concurrentes (jamais SQLite) :
 * - un verrou relit l'état COURANT, même si un instantané REPEATABLE READ existe déjà dans la transaction ;
 * - verrou partagé et exclusif gardent leur sémantique ; aucun verrou n'survit à la transaction.
 */
#[Group('requires-event-schema')]
final class EventLocksTest extends EventSchemaTestCase
{
    private EventScenario $s;
    private Connection $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = EventScenario::create();
        $this->save(...$this->s->entities);
        $this->em->clear();
        self::assertStringContainsString('_test', (string) $this->em->getConnection()->getDatabase());
        $this->other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $this->other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        while ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        while ($this->other->isTransactionActive()) {
            $this->other->rollBack();
        }
        $this->other->close();
        parent::tearDown();
    }

    private function events(): EventRepository
    {
        return $this->service(EventRepository::class);
    }

    private function eventId(): Uuid
    {
        return $this->s->event->id();
    }

    /** Ouvre la transaction principale et y établit l'instantané REPEATABLE READ par une lecture simple. */
    private function beginWithSnapshot(): void
    {
        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_type');
    }

    private function renameFromAnotherTransaction(string $name): void
    {
        $this->other->executeStatement('UPDATE event_event SET name = ? WHERE id = ?', [$name, $this->eventId()->toBinary()]);
    }

    // ── Relecture sous verrou ────────────────────────────────────────────────

    public function testExclusiveLockRereadsTheCurrentStateEvenAfterASnapshotAndAManagedEntity(): void
    {
        $managed = $this->em->find(Event::class, $this->eventId()) ?? self::fail('Événement absent.');
        $oldName = $managed->name();
        $this->beginWithSnapshot();
        $this->renameFromAnotherTransaction('Nom validé par une autre transaction');

        $locked = $this->events()->lock($this->eventId());

        self::assertSame($managed, $locked, 'même entité gérée, relue sous verrou');
        self::assertSame('Nom validé par une autre transaction', $locked->name(), 'état courant (pas l\'instantané périmé)');
        self::assertNotSame($oldName, $locked->name());
    }

    public function testASimpleRefreshAfterTheLockWouldReadAStaleSnapshot(): void
    {
        // Démonstration du défaut corrigé : l'ancien lock() faisait find(verrou) PUIS refresh() simple.
        $this->em->find(Event::class, $this->eventId());
        $oldName = $this->s->event->name();
        $this->beginWithSnapshot();
        $this->renameFromAnotherTransaction('Nom plus récent');

        $locked = $this->events()->lock($this->eventId());
        self::assertSame('Nom plus récent', $locked->name());
        $this->em->refresh($locked); // refresh SIMPLE : instantané REPEATABLE READ

        self::assertSame($oldName, $locked->name(), 'un refresh simple relit l\'état périmé : il ne doit jamais suivre le verrou');
    }

    public function testSharedLockAlsoRereadsTheCurrentState(): void
    {
        $this->em->find(Event::class, $this->eventId());
        $this->beginWithSnapshot();
        $this->renameFromAnotherTransaction('Nom courant (partagé)');

        $locked = $this->events()->lockShared($this->eventId());

        self::assertNotNull($locked);
        self::assertSame('Nom courant (partagé)', $locked->name());
    }

    public function testParticipationAndMunicipalityLocksRereadTheCurrentState(): void
    {
        $s = $this->s;
        $this->em->find(EventParticipation::class, $s->participationB->id());
        $this->em->find(Municipality::class, $s->b->id());
        $this->beginWithSnapshot();
        $this->other->executeStatement("UPDATE event_participation SET status = 'SUSPENDED' WHERE id = ?", [$s->participationB->id()->toBinary()]);
        $this->other->executeStatement("UPDATE municipality SET name = 'Commune renommée' WHERE id = ?", [$s->b->id()->toBinary()]);

        $participation = $this->service(EventParticipationRepository::class)->lock($s->participationB->id());
        $municipality = $this->service(MunicipalityRepository::class)->lock($s->b->id());

        self::assertSame(ParticipationStatus::Suspended, $participation?->status());
        self::assertSame('Commune renommée', $municipality?->name());
    }

    // ── Sémantique des modes ─────────────────────────────────────────────────

    public function testSharedLocksAreCompatibleButExcludeAnExclusiveLock(): void
    {
        $id = $this->eventId()->toBinary();
        $this->em->getConnection()->beginTransaction();
        self::assertNotNull($this->events()->lockShared($this->eventId()));

        $this->other->beginTransaction();
        self::assertNotFalse($this->other->fetchOne('SELECT id FROM event_event WHERE id = ? FOR SHARE', [$id]), 'un second verrou partagé est accordé');
        $this->other->rollBack();

        $this->other->beginTransaction();
        $this->expectException(LockWaitTimeoutException::class);
        $this->other->fetchOne('SELECT id FROM event_event WHERE id = ? FOR UPDATE', [$id]);
    }

    public function testAnExclusiveLockExcludesEverythingElse(): void
    {
        $id = $this->eventId()->toBinary();
        $this->em->getConnection()->beginTransaction();
        self::assertNotNull($this->events()->lock($this->eventId()));

        foreach (['FOR SHARE', 'FOR UPDATE'] as $mode) {
            $this->other->beginTransaction();
            try {
                $this->other->fetchOne('SELECT id FROM event_event WHERE id = ? '.$mode, [$id]);
                self::fail('Le verrou exclusif doit exclure '.$mode);
            } catch (LockWaitTimeoutException) {
                self::addToAssertionCount(1);
            }
            $this->other->rollBack();
        }
        // Une lecture simple n'est jamais bloquée (lecture cohérente).
        self::assertNotFalse($this->other->fetchOne('SELECT id FROM event_event WHERE id = ?', [$id]));
    }

    public function testNoLockSurvivesTheTransaction(): void
    {
        $id = $this->eventId()->toBinary();
        $this->em->getConnection()->beginTransaction();
        $this->events()->lock($this->eventId());
        $this->em->getConnection()->commit();

        $this->other->beginTransaction();
        self::assertNotFalse($this->other->fetchOne('SELECT id FROM event_event WHERE id = ? FOR UPDATE', [$id]), 'verrou libéré au commit');
        $this->other->rollBack();

        $this->em->getConnection()->beginTransaction();
        $this->events()->lockShared($this->eventId());
        $this->em->getConnection()->rollBack();
        $this->other->beginTransaction();
        self::assertNotFalse($this->other->fetchOne('SELECT id FROM event_event WHERE id = ? FOR UPDATE', [$id]), 'verrou libéré au rollback');
    }

    public function testLockingAMissingResourceReturnsNull(): void
    {
        $this->em->getConnection()->beginTransaction();

        self::assertNull($this->events()->lock(Uuid::v7()));
        self::assertNull($this->events()->lockShared(Uuid::v7()));
    }

    // ── EventLocker : ordre et garde-fous ────────────────────────────────────

    public function testLockerOnlyWorksInsideATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->service(EventLocker::class)->exclusiveEvent($this->eventId());
    }

    public function testLockerAcquiresTheEventThenItsParticipationAndRefusesForeignOnes(): void
    {
        $locker = $this->service(EventLocker::class);
        $this->em->getConnection()->beginTransaction();

        $event = $locker->sharedEvent($this->eventId());
        $participation = $locker->participation($this->s->participationB->id(), $event);
        self::assertTrue($participation->event()->id()->equals($event->id()));

        // Participation d'un autre événement : même refus qu'une ressource inexistante.
        $foreignEvent = EventFixtures::draft(
            $this->em->find(Municipality::class, $this->s->a->id()) ?? self::fail('Commune absente.'),
            $this->em->find(User::class, $this->s->adminA->id()) ?? self::fail('Utilisateur absent.'),
            $event->type(),
        );
        $foreignParticipation = EventFixtures::organizerParticipation($foreignEvent);
        $this->save($foreignEvent, $foreignParticipation);
        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('Ressource inaccessible.');
        $locker->participation($foreignParticipation->id(), $event);
    }

    public function testLockerRefusesAnUnknownEventUniformly(): void
    {
        $this->em->getConnection()->beginTransaction();

        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('Ressource inaccessible.');
        $this->service(EventLocker::class)->exclusiveEvent(Uuid::v7());
    }
}
