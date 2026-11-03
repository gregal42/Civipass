<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Domain\EventReferenceCounter;
use App\Event\Domain\PublicReferenceGenerator;
use App\Tests\Support\EventSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-event-schema')]
final class PublicReferenceGeneratorTest extends EventSchemaTestCase
{
    private function generator(): PublicReferenceGenerator
    {
        return $this->service(PublicReferenceGenerator::class);
    }

    public function testCounterRowIsInitializedByTheMigration(): void
    {
        $counter = $this->em->find(EventReferenceCounter::class, EventReferenceCounter::SINGLE_ROW_ID);

        self::assertNotNull($counter, 'la migration doit créer la ligne unique (last_number = 0)');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_reference_counter'));
    }

    public function testGenerationRequiresATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->generator()->next(new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')));
    }

    public function testReferencesIncreaseWithinTransactions(): void
    {
        $at = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));

        $references = $this->transactional(fn (): array => [$this->generator()->next($at)->value, $this->generator()->next($at)->value]);

        self::assertSame(['EVT-2026-000001', 'EVT-2026-000002'], $references);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM event_reference_counter'));
    }

    public function testRollbackReleasesTheNumber(): void
    {
        $at = new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));
        $connection = $this->em->getConnection();
        $this->transactional(fn () => $this->generator()->next($at));

        $connection->beginTransaction();
        $this->generator()->next($at);
        $connection->rollBack();

        $next = $this->transactional(fn () => $this->generator()->next($at)->value);

        self::assertSame('EVT-2026-000002', $next, 'le numéro de la transaction annulée est réutilisé : aucun trou');
    }

    public function testTheCounterIsGlobalAndNeverRestartsWithTheYear(): void
    {
        $december = $this->transactional(fn () => $this->generator()->next(new \DateTimeImmutable('2026-12-31 23:00:00', new \DateTimeZone('UTC')))->value);
        $january = $this->transactional(fn () => $this->generator()->next(new \DateTimeImmutable('2027-01-01 01:00:00', new \DateTimeZone('UTC')))->value);

        self::assertSame('EVT-2026-000001', $december);
        self::assertSame('EVT-2027-000002', $january);
    }
}
