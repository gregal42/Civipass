<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\ParticipationStatus;
use App\Tests\Support\EventScenario;
use App\Tests\Support\EventSchemaTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\Group;

/**
 * EventParticipationRepository::lockByEvent sur MySQL réel, deux connexions : toutes les participations (plusieurs
 * communes) sont verrouillées, dans l'ordre croissant des identifiants, relues sous verrou, et libérées au commit comme au rollback.
 */
#[Group('requires-event-schema')]
final class EventParticipationLockTest extends EventSchemaTestCase
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

    private function repository(): EventParticipationRepository
    {
        return $this->service(EventParticipationRepository::class);
    }

    private function begin(): void
    {
        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_type'); // instantané REPEATABLE READ
    }

    private function touch(EventParticipation $participation): void
    {
        $this->other->executeStatement('UPDATE event_participation SET updated_at = updated_at WHERE id = ?', [$participation->id()->toBinary()]);
    }

    private function isBlocked(EventParticipation $participation): bool
    {
        try {
            $this->touch($participation);

            return false;
        } catch (LockWaitTimeoutException) {
            return true;
        }
    }

    public function testLocksEveryParticipationOfTheEventAcrossMunicipalitiesInAscendingIdOrder(): void
    {
        $s = $this->s;
        $this->begin();

        $locked = $this->repository()->lockByEvent($s->event->id());

        self::assertCount(3, $locked, 'organisatrice A, B et D');
        $ids = array_map(static fn (EventParticipation $p): string => $p->id()->toBinary(), $locked);
        $sorted = $ids;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $ids, 'ordre croissant des identifiants (ordre d\'acquisition déterministe)');
        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            self::assertTrue($this->isBlocked($participation), 'la participation de '.$participation->municipality()->name().' est verrouillée');
        }
    }

    public function testOnlyTheParticipationsOfThatEventAreLocked(): void
    {
        $s = $this->s;
        $organizer = $this->em->find(\App\Municipality\Domain\Municipality::class, $s->a->id()) ?? self::fail();
        $creator = $this->em->find(\App\Identity\Domain\User::class, $s->adminA->id()) ?? self::fail();
        $type = $this->em->find(\App\Event\Domain\EventType::class, $s->event->type()->id()) ?? self::fail();
        $otherEvent = \App\Tests\Support\EventFixtures::draft($organizer, $creator, $type);
        $foreign = EventParticipation::forOrganizer($otherEvent, \App\Tests\Support\EventFixtures::now());
        $this->save($otherEvent, $foreign);
        $this->em->clear();
        $this->begin();

        $locked = $this->repository()->lockByEvent($s->event->id());

        self::assertCount(3, $locked);
        self::assertFalse($this->isBlocked($foreign), 'la participation d\'un autre événement reste libre');
    }

    public function testRereadsTheCurrentStateUnderLockEvenWithAManagedEntityAndASnapshot(): void
    {
        $s = $this->s;
        $this->em->find(EventParticipation::class, $s->participationB->id());
        $this->begin();
        $this->other->executeStatement("UPDATE event_participation SET status = 'SUSPENDED' WHERE id = ?", [$s->participationB->id()->toBinary()]);

        $byId = [];
        foreach ($this->repository()->lockByEvent($s->event->id()) as $p) {
            $byId[$p->id()->toRfc4122()] = $p;
        }

        self::assertSame(ParticipationStatus::Suspended, $byId[$s->participationB->id()->toRfc4122()]->status());
    }

    public function testAConcurrentTransactionWaitsThenFailsOnALockedParticipationButNotOnceReleasedByCommit(): void
    {
        $s = $this->s;
        $this->begin();
        $this->repository()->lockByEvent($s->event->id());

        self::assertTrue($this->isBlocked($s->participationB));

        $this->em->getConnection()->commit();
        self::assertFalse($this->isBlocked($s->participationB), 'verrou libéré au commit');
        self::assertFalse($this->isBlocked($s->participationA));
        self::assertFalse($this->isBlocked($s->participationD));
    }

    public function testLocksAreReleasedByRollback(): void
    {
        $s = $this->s;
        $this->begin();
        $this->repository()->lockByEvent($s->event->id());
        self::assertTrue($this->isBlocked($s->participationD));

        $this->em->getConnection()->rollBack();

        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            self::assertFalse($this->isBlocked($participation), 'verrou libéré au rollback');
        }
    }

    public function testTwoGlobalLocksAreSerializedWithoutDeadlock(): void
    {
        $s = $this->s;
        $this->begin();
        $this->repository()->lockByEvent($s->event->id());

        try {
            $this->other->beginTransaction();
            $this->other->fetchAllAssociative('SELECT id FROM event_participation WHERE event_id = ? ORDER BY id FOR UPDATE', [$s->event->id()->toBinary()]);
            self::fail('Le second verrou global doit attendre puis échouer par délai, jamais par interblocage.');
        } catch (LockWaitTimeoutException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTheLockQueryReadsRowsInPrimaryKeyOrderOfThatEvent(): void
    {
        $plan = $this->em->getConnection()->fetchAllAssociative('EXPLAIN SELECT id FROM event_participation WHERE event_id = ? ORDER BY id FOR UPDATE', [$this->s->event->id()->toBinary()]);

        self::assertNotEmpty($plan);
        self::assertStringNotContainsString('filesort', strtolower((string) ($plan[0]['Extra'] ?? '')), 'pas de tri : l\'ordre de parcours (donc d\'acquisition) est celui de l\'index : '.json_encode($plan[0]));
    }
}
