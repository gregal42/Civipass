<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\ClosureStatistics;
use App\Event\Domain\MunicipalityCounters;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use PHPUnit\Framework\TestCase;

/** Accesseurs et écriture CONTRÔLÉE des compteurs de clôture sur `Event` (LOT 05.6.2) : écriture unique, seulement sur un événement clos, relecture stricte. */
final class EventClosureStatisticsTest extends TestCase
{
    private static function statistics(): ClosureStatistics
    {
        return new ClosureStatistics(1, [new MunicipalityCounters('0192a5f0-0000-7000-8000-00000000000a', 1, 0, 0, 1)]);
    }

    public function testAnEventHasNoStatisticsUntilItIsClosed(): void
    {
        $event = F::active();

        self::assertNull($event->closureStatistics());
        $this->expectException(DomainRuleViolation::class);
        $event->recordClosureStatistics(self::statistics());
    }

    public function testStatisticsAreRecordedOnceOnAClosedEventAndNeverRecalculated(): void
    {
        $event = F::active();
        $event->close(F::now());

        $event->recordClosureStatistics(self::statistics());

        self::assertEquals(self::statistics(), $event->closureStatistics());
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('aucun recalcul');
        $event->recordClosureStatistics(new ClosureStatistics(0, []));
    }

    public function testACorruptedStoredDocumentIsRefusedOnReadInsteadOfBeingServed(): void
    {
        $event = F::active();
        $event->close(F::now());
        $property = new \ReflectionProperty($event, 'closureStatistics');
        $property->setValue($event, ['v' => 1, 'internal' => ['requests' => 1, 'municipalities' => [], 'nom' => 'Dupont']]);

        $this->expectException(DomainRuleViolation::class);
        $event->closureStatistics();
    }
}
