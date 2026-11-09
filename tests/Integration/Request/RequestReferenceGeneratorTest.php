<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Domain\Time\EventTimezone;
use App\Request\Domain\RequestReferenceGenerator;
use App\Tests\Support\RequestSchemaTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Garanties du compteur annuel sur MySQL : numéros uniques et consécutifs, remise à 1 chaque année, rollback sans trou.
 */
#[Group('requires-request-schema')]
final class RequestReferenceGeneratorTest extends RequestSchemaTestCase
{
    private function generator(): RequestReferenceGenerator
    {
        return $this->service(RequestReferenceGenerator::class);
    }

    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function next(string $at, string $timezone = 'Europe/Paris'): string
    {
        return $this->em->wrapInTransaction(fn (): string => $this->generator()->next(self::at($at), EventTimezone::fromIdentifier($timezone))->value);
    }

    public function testNumbersAreSequentialUniqueAndStartAtOne(): void
    {
        $references = [];
        for ($i = 0; $i < 12; ++$i) {
            $references[] = $this->next('2026-06-01 10:00:00');
        }

        self::assertSame('DEM-2026-0001', $references[0]);
        self::assertSame('DEM-2026-0012', $references[11]);
        self::assertCount(12, array_unique($references));
    }

    public function testLockingTheCounterConsumesNoNumberAndIsRolledBackWithTheTransaction(): void
    {
        $this->em->wrapInTransaction(fn () => $this->generator()->lock(self::at('2026-06-01 10:00:00'), EventTimezone::fromIdentifier('Europe/Paris')));
        self::assertSame('DEM-2026-0001', $this->next('2026-06-01 10:00:00'), 'le verrou seul ne consomme aucun numéro');

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->generator()->lock(self::at('2026-06-01 10:00:00'), EventTimezone::fromIdentifier('Europe/Paris'));
        $connection->rollBack();
        $this->next('2026-06-01 10:00:00'); // DEM-2026-0002
        self::assertSame('DEM-2026-0003', $this->next('2026-06-01 10:00:00'));
        $this->expectException(\LogicException::class);
        $this->generator()->lock(self::at('2026-06-01 10:00:00'), EventTimezone::fromIdentifier('Europe/Paris'));
    }

    public function testEachYearHasItsOwnCounterStartingAtOne(): void
    {
        $this->next('2026-06-01 10:00:00');
        $this->next('2026-06-02 10:00:00');

        self::assertSame('DEM-2027-0001', $this->next('2027-01-05 10:00:00'));
        self::assertSame('DEM-2026-0003', $this->next('2026-12-01 10:00:00'));
        self::assertSame('DEM-2027-0002', $this->next('2027-02-01 10:00:00'));
    }

    public function testTheYearFollowsTheEventTimezone(): void
    {
        // 31/12/2026 23:30 UTC : 2027 à Paris, 2026 à Tahiti.
        self::assertSame('DEM-2027-0001', $this->next('2026-12-31 23:30:00', 'Europe/Paris'));
        self::assertSame('DEM-2026-0001', $this->next('2026-12-31 23:30:00', 'Pacific/Tahiti'));
    }

    public function testARollbackLeavesNoGap(): void
    {
        $this->next('2026-06-01 10:00:00');

        // Transaction explicite : wrapInTransaction() fermerait l'EntityManager sur exception (DT-007).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->generator()->next(self::at('2026-06-01 10:00:00'), EventTimezone::fromIdentifier('Europe/Paris'));
        $connection->rollBack();

        self::assertSame('DEM-2026-0002', $this->next('2026-06-01 10:00:00'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM request_reference_counter WHERE year = 2026'));
    }

    public function testGenerationOutsideATransactionIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->generator()->next(self::at('2026-06-01 10:00:00'), EventTimezone::fromIdentifier('Europe/Paris'));
    }
}
