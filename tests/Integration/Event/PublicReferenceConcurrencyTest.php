<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Infrastructure\DoctrinePublicReferenceGenerator;
use App\Tests\Support\EventSchemaTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Garanties transactionnelles du compteur global sur MySQL (jamais SQLite : le verrouillage de ligne y diffère).
 * Seules des connexions réelles et concurrentes à la base de TEST sont utilisées.
 */
#[Group('requires-event-schema')]
final class PublicReferenceConcurrencyTest extends EventSchemaTestCase
{
    private Connection $second;

    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) $this->em->getConnection()->getDatabase();
        self::assertStringContainsString('_test', $database, 'concurrence : base de test uniquement');
        $this->second = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        if (isset($this->second)) {
            while ($this->second->isTransactionActive()) {
                $this->second->rollBack();
            }
            $this->second->close();
        }
        parent::tearDown();
    }

    private static function at(string $value = '2026-06-01 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function counter(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM event_reference_counter WHERE id = 1');
    }

    public function testCase1SequentialGenerationIsUniqueAndOrdered(): void
    {
        $generator = new DoctrinePublicReferenceGenerator($this->em->getConnection());
        $references = [];
        for ($i = 0; $i < 20; ++$i) {
            $references[] = $this->em->wrapInTransaction(fn (): string => $generator->next(self::at())->value);
        }

        self::assertCount(20, array_unique($references));
        self::assertSame(sprintf('EVT-2026-%06d', 1), $references[0]);
        self::assertSame(sprintf('EVT-2026-%06d', 20), $references[19]);
        $sorted = $references;
        sort($sorted);
        self::assertSame($sorted, $references);
        self::assertSame(20, $this->counter());
    }

    public function testCase2ASecondTransactionWaitsForTheFirstAndNeverSharesANumber(): void
    {
        $first = new DoctrinePublicReferenceGenerator($this->em->getConnection());
        $second = new DoctrinePublicReferenceGenerator($this->second);
        $this->second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->em->getConnection()->beginTransaction();
        $reserved = $first->next(self::at())->value;

        $this->second->beginTransaction();
        try {
            $second->next(self::at());
            self::fail('La seconde transaction doit attendre le verrou de ligne de la première.');
        } catch (LockWaitTimeoutException) {
            self::addToAssertionCount(1);
        }
        $this->second->rollBack();

        $this->em->getConnection()->commit();
        $this->second->beginTransaction();
        $next = $second->next(self::at())->value;
        $this->second->commit();

        self::assertSame('EVT-2026-000001', $reserved);
        self::assertSame('EVT-2026-000002', $next);
        self::assertSame(2, $this->counter());
    }

    public function testCase2BParallelProcessesNeverObtainTheSameNumber(): void
    {
        $processes = 4;
        $perProcess = 25;
        $running = [];
        for ($i = 0; $i < $processes; ++$i) {
            // stderr vers un fichier : le journal verbeux du noyau remplirait un tube non lu et bloquerait le processus.
            $errorFile = tempnam(sys_get_temp_dir(), 'civipass-refs-');
            self::assertNotFalse($errorFile);
            $handle = proc_open(
                [\PHP_BINARY, \dirname(__DIR__, 2).'/Support/generate-references.php', (string) $perProcess],
                [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
                $pipes,
                \dirname(__DIR__, 3),
            );
            self::assertIsResource($handle);
            $running[] = [$handle, $pipes, $errorFile];
        }

        $all = [];
        foreach ($running as [$handle, $pipes, $errorFile]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $status = proc_close($handle);
            $stderr = (string) file_get_contents($errorFile);
            unlink($errorFile);
            self::assertSame(0, $status, 'processus de génération en échec : '.substr($stderr, -500));
            /** @var list<string> $references */
            $references = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
            $all = [...$all, ...$references];
        }

        self::assertCount($processes * $perProcess, $all);
        self::assertCount($processes * $perProcess, array_unique($all), 'aucun numéro attribué deux fois');
        $expected = array_map(static fn (int $n): string => sprintf('EVT-2026-%06d', $n), range(1, $processes * $perProcess));
        sort($all);
        self::assertSame($expected, $all, 'séquence continue, sans trou');
        self::assertSame($processes * $perProcess, $this->counter());
    }

    public function testCase3RollbackAfterReservationLeavesNoGap(): void
    {
        $first = new DoctrinePublicReferenceGenerator($this->em->getConnection());
        $second = new DoctrinePublicReferenceGenerator($this->second);

        $this->em->wrapInTransaction(fn () => $first->next(self::at()));
        $this->second->beginTransaction();
        self::assertSame('EVT-2026-000002', $second->next(self::at())->value);
        self::assertSame(2, $this->secondCounter());
        $this->second->rollBack();

        self::assertSame(1, $this->counter(), 'l\'incrément de la transaction annulée a disparu');
        self::assertSame('EVT-2026-000002', $this->em->wrapInTransaction(fn (): string => $first->next(self::at())->value));
    }

    private function secondCounter(): int
    {
        return (int) $this->second->fetchOne('SELECT last_number FROM event_reference_counter WHERE id = 1');
    }

    public function testCase3ARollbackWhileAnotherTransactionWaitsHandsOverTheSameNumber(): void
    {
        $first = new DoctrinePublicReferenceGenerator($this->em->getConnection());
        $second = new DoctrinePublicReferenceGenerator($this->second);
        $this->second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->em->getConnection()->beginTransaction();
        $first->next(self::at());
        $this->second->beginTransaction();
        try {
            $second->next(self::at());
            self::fail('Attendu : attente du verrou.');
        } catch (LockWaitTimeoutException) {
            self::addToAssertionCount(1);
        }
        $this->second->rollBack();
        $this->em->getConnection()->rollBack();

        $this->second->beginTransaction();
        self::assertSame('EVT-2026-000001', $second->next(self::at())->value);
        $this->second->commit();
    }

    public function testCase4YearComesFromTheUtcCreationDateAndTheCounterNeverRestarts(): void
    {
        $generator = new DoctrinePublicReferenceGenerator($this->em->getConnection());
        $dates = [
            '2026-12-31 22:59:59' => 'EVT-2026-000001',
            '2026-12-31 23:00:00' => 'EVT-2026-000002',
            '2027-01-01 00:00:00' => 'EVT-2027-000003',
            '2028-02-29 12:00:00' => 'EVT-2028-000004',
        ];

        foreach ($dates as $date => $expected) {
            self::assertSame($expected, $this->em->wrapInTransaction(fn (): string => $generator->next(self::at($date))->value));
        }

        // 1er janvier 00:30 à Paris = 31 décembre 23:30 UTC : l'année est celle de l'UTC.
        $paris = new \DateTimeImmutable('2029-01-01 00:30:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame('EVT-2028-000005', $this->em->wrapInTransaction(fn (): string => $generator->next($paris)->value));
    }

    public function testCase5NumberingGoesBeyondSixDigitsWithoutErrorOrReset(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE event_reference_counter SET last_number = 999998 WHERE id = 1');
        $generator = new DoctrinePublicReferenceGenerator($this->em->getConnection());

        $references = [];
        for ($i = 0; $i < 3; ++$i) {
            $references[] = $this->em->wrapInTransaction(fn (): string => $generator->next(self::at())->value);
        }

        self::assertSame(['EVT-2026-999999', 'EVT-2026-1000000', 'EVT-2026-1000001'], $references);
        self::assertSame(1000001, $this->counter());
        self::assertLessThanOrEqual(20, \strlen($references[2]), 'tient dans la colonne public_reference');
    }
}
