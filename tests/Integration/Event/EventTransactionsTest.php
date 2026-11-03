<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventType;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\EventScenario;
use App\Tests\Support\EventSchemaTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Transaction métier : délai de verrou de session (restauré), traduction ciblée des conflits, rien d'autre n'est masqué.
 */
#[Group('requires-event-schema')]
final class EventTransactionsTest extends EventSchemaTestCase
{
    private function transactions(int $seconds = 5): EventTransactions
    {
        return new EventTransactions($this->service(TransactionRunner::class), $this->em->getConnection(), $seconds);
    }

    private function timeout(Connection $connection): int
    {
        return (int) $connection->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout');
    }

    public function testTheSessionLockWaitIsFiveSecondsDuringTheOperationThenRestored(): void
    {
        $connection = $this->em->getConnection();
        $before = $this->timeout($connection);
        self::assertSame(50, $before, 'valeur par défaut du serveur, jamais modifiée globalement');

        $during = $this->transactions()->run(fn (): int => $this->timeout($connection));

        self::assertSame(5, $during);
        self::assertSame($before, $this->timeout($connection));
        self::assertSame(50, (int) $connection->fetchOne('SELECT @@GLOBAL.innodb_lock_wait_timeout'));
    }

    public function testTheSessionLockWaitIsRestoredAfterAFailure(): void
    {
        $connection = $this->em->getConnection();

        try {
            $this->transactions()->run(static fn () => throw new DomainRuleViolation('refus'));
            // @phpstan-ignore-next-line
            self::fail('Refus attendu.');
        } catch (DomainRuleViolation) {
            self::addToAssertionCount(1);
        }
        self::assertSame(50, $this->timeout($connection));
    }

    public function testLockWaitTimeoutBecomesAnOperationConflictAndNothingIsWritten(): void
    {
        $s = EventScenario::create();
        $this->save(...$s->entities);
        $eventId = $s->event->id();
        $holder = DriverManager::getConnection($this->em->getConnection()->getParams());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT id FROM event_event WHERE id = ? FOR UPDATE', [$eventId->toBinary()]);
        $locker = $this->service(EventLocker::class);

        try {
            $this->transactions(1)->run(function () use ($locker, $eventId): void {
                $this->em->persist(new EventType('conflict_test', 'Ne doit pas persister'));
                $locker->exclusiveEvent($eventId);
            });
            self::fail('Le verrou détenu par une autre transaction doit expirer.');
        } catch (OperationConflict $e) {
            self::assertSame(ConflictCause::LockWaitTimeout, $e->cause);
            self::assertStringContainsString('réessayez', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        } finally {
            $holder->rollBack();
            $holder->close();
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM event_type WHERE code = 'conflict_test'"), 'écriture annulée');
        self::assertFalse($this->em->isOpen(), 'le runner ferme l\'EntityManager après un échec technique : pas de nouvelle tentative automatique');
        self::assertSame(50, $this->timeout($this->em->getConnection()), 'délai de session restauré');
    }

    public function testADeadlockBecomesAnOperationConflict(): void
    {
        // Même chaîne de conversion que celle de DBAL pour le code serveur 1213 (l'interblocage réel n'est pas déterministe).
        $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213);
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        $deadlock = $this->em->getConnection()->getDriver()->getExceptionConverter()->convert(PdoDriverException::new($pdo), null);

        try {
            $this->transactions()->run(static fn () => throw $deadlock);
            // @phpstan-ignore-next-line
            self::fail('Conflit attendu.');
        } catch (OperationConflict $e) {
            self::assertSame(ConflictCause::Deadlock, $e->cause);
        }
    }

    public function testADeclaredUniqueConstraintBecomesAConflictOthersAreNeverMasked(): void
    {
        $s = EventScenario::create();
        $this->save(...$s->entities);
        $duplicate = fn () => $this->em->getConnection()->executeStatement(
            'INSERT INTO event_participation (id, status, created_at, updated_at, event_id, municipality_id) VALUES (?, ?, ?, ?, ?, ?)',
            [random_bytes(16), 'INVITED', '2026-06-01 10:00:00', '2026-06-01 10:00:00', $s->event->id()->toBinary(), $s->d->id()->toBinary()],
        );

        try {
            $this->transactions()->run($duplicate, ['uniq_event_participation_event_municipality']);
            self::fail('Conflit attendu.');
        } catch (OperationConflict $e) {
            self::assertSame(ConflictCause::UniqueViolation, $e->cause);
            self::assertInstanceOf(UniqueConstraintViolationException::class, $e->getPrevious());
        }
    }

    public function testAnUndeclaredUniqueConstraintIsRethrownUntouched(): void
    {
        $this->save(new EventType('dup_code', 'Premier'));
        $connection = $this->em->getConnection();
        $insert = fn () => $connection->executeStatement('INSERT INTO event_type (id, code, label, sort_order, active) VALUES (?, ?, ?, 0, 1)', [random_bytes(16), 'dup_code', 'Doublon']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->transactions()->run($insert, ['uniq_event_participation_event_municipality']);
    }

    public function testProgrammingErrorsAndBusinessRefusalsAreNeverTranslated(): void
    {
        foreach ([new \LogicException('bug'), new \InvalidArgumentException('autre'), new DomainRuleViolation('métier')] as $failure) {
            try {
                $this->transactions()->run(static fn () => throw $failure);
                // @phpstan-ignore-next-line
                self::fail('Exception attendue.');
            } catch (\Throwable $caught) {
                self::assertSame($failure, $caught);
            }
        }
    }

    public function testTheLockWaitDelayIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->transactions(0);
    }
}
