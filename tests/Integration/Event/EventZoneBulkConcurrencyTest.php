<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateZoneCommand;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ZoneConcurrencyTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence des opérations groupées (C9 et suivantes) : sérialisées avec les autres opérations de zone, l'activation
 * et la clôture ; jamais d'état partiel, jamais d'interblocage, un audit par objet réellement modifié.
 */
#[Group('requires-event-schema')]
final class EventZoneBulkConcurrencyTest extends ZoneConcurrencyTestCase
{
    /** @var list<string> */
    private array $zones = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Route A', 'Route B', 'Route C'] as $label) {
            $this->zones[] = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand($label, 'STREET'))->zoneId;
        }
    }

    /**
     * @param list<string>|null $zones
     *
     * @return array<string, mixed>
     */
    private function selection(?array $zones = null): array
    {
        return ['zoneIds' => $zones ?? $this->zones];
    }

    private function statusCount(string $status): int
    {
        return $this->rows("SELECT COUNT(*) FROM event_zone WHERE status = '".$status."' AND label LIKE 'Route %'");
    }

    private function batches(string $action): int
    {
        return $this->rows("SELECT COUNT(DISTINCT batch_id) FROM audit_log WHERE action = '".$action."'");
    }

    public function testTwoBulkOperationsOnTheSameParticipationAreSerialized(): void
    {
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $second = $this->startSecond($this->org(), 'bulk-reactivate', $this->created->participationId, $this->selection());
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        $secondResult = $this->outcome($second);

        self::assertTrue($secondResult['ok'], 'la seconde voit les trois zones suspendues et les réactive : '.json_encode($secondResult));
        self::assertSame(3, $this->statusCount('ACTIVE'));
        self::assertSame(3, $this->auditCount('ZONE_SUSPENDED'));
        self::assertSame(3, $this->auditCount('ZONE_REACTIVATED'));
        self::assertSame(2, $this->auditCount('ZONE_BULK_OPERATION'));
        self::assertSame(1, $this->batches('ZONE_SUSPENDED'));
        self::assertSame(1, $this->batches('ZONE_REACTIVATED'));
        self::assertSame(2, $this->rows("SELECT COUNT(DISTINCT batch_id) FROM audit_log WHERE action IN ('ZONE_SUSPENDED','ZONE_REACTIVATED')"), 'C9 : batch_id distincts');
    }

    public function testTwoIdenticalBulkSuspensionsApplyOnce(): void
    {
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $second = $this->startSecond($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertFalse($this->outcome($second)['ok'], 'les zones sont déjà suspendues : refus en bloc');
        self::assertSame(3, $this->auditCount('ZONE_SUSPENDED'));
        self::assertSame(1, $this->auditCount('ZONE_BULK_OPERATION'));
    }

    public function testOppositeSelectionOrdersNeverDeadlock(): void
    {
        $payloadA = ['zoneIds' => $this->zones, 'mode' => 'EVENT_DURATION', 'confirm' => true];
        $payloadB = ['zoneIds' => array_reverse($this->zones), 'mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot()], 'confirm' => true];

        for ($round = 0; $round < 3; ++$round) {
            $results = array_map($this->outcome(...), [
                $this->startSecond($this->org(), 'bulk-validity', $this->created->participationId, $payloadA),
                $this->startSecond($this->org(), 'bulk-validity', $this->created->participationId, $payloadB),
                $this->startSecond($this->org(), 'bulk-validity', $this->created->participationId, $payloadA),
            ]);
            foreach ($results as $result) {
                self::assertTrue($result['ok'], 'aucun interblocage ni délai : '.json_encode($result));
            }
        }
        // État final cohérent : chaque zone a soit aucun créneau actif (durée), soit exactement un (périodes), jamais les deux.
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_zone z WHERE z.label LIKE 'Route %' AND ((z.validity_mode = 'EVENT_DURATION' AND EXISTS (SELECT 1 FROM event_zone_validity v WHERE v.zone_id = z.id AND v.deleted_at IS NULL)) OR (z.validity_mode = 'SPECIFIC_PERIODS' AND (SELECT COUNT(*) FROM event_zone_validity v WHERE v.zone_id = z.id AND v.deleted_at IS NULL) <> 1))"));
    }

    // ── Groupé et clôture ────────────────────────────────────────────────────

    public function testClosureWaitsForARunningBulkOperationThenClosesEverything(): void
    {
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $second = $this->startSecond($this->org(), 'close-event', $this->created->eventId);
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(3, $this->statusCount('SUSPENDED'), 'le lot validé avant la clôture est conservé');
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"));
    }

    public function testABulkOperationWaitsForARunningClosureAndIsRefused(): void
    {
        $first = $this->startHolding($this->org(), 'close-event', $this->created->eventId);
        $second = $this->startSecond($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertFalse($this->outcome($second)['ok']);
        self::assertSame(0, $this->statusCount('SUSPENDED'), 'aucune zone modifiée après la clôture');
        self::assertSame(0, $this->auditCount('ZONE_BULK_OPERATION'));
    }

    // ── Groupé et activation ─────────────────────────────────────────────────

    public function testActivationWaitsForABulkSuspensionOfTheOnlyReadyZonesAndIsRefused(): void
    {
        $this->configureAllZones();
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, ['zoneIds' => $this->zones]);
        $second = $this->startSecond($this->org(), 'activate', $this->created->eventId);
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertFalse($this->outcome($second)['ok'], 'aucune zone active et prête après le lot validé');
        self::assertSame('DRAFT', $this->eventStatus());
        self::assertSame(0, $this->auditCount('EVENT_ACTIVATED'));
    }

    public function testABulkSuspensionWaitsForARunningActivationThenAppliesToTheActiveEvent(): void
    {
        $this->configureAllZones();

        $first = $this->startHolding($this->org(), 'activate', $this->created->eventId);
        $second = $this->startSecond($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);
        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame(3, $this->statusCount('SUSPENDED'));
        self::assertSame(1, $this->auditCount('EVENT_ACTIVATED'));
    }

    /** Les trois zones « Route » sont confirmées et configurées (durée de l'événement) : l'événement est activable. */
    private function configureAllZones(): void
    {
        foreach ($this->zones as $zone) {
            $this->zoneService()->configureValidity($this->as($this->w->adminO), $zone, $this->eventDuration());
        }
    }

    // ── Individuel contre groupé ─────────────────────────────────────────────

    public function testAnIndividualOperationOnAZoneOfARunningBulkOperationWaitsAndSeesItsResult(): void
    {
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $suspend = $this->startSecond($this->org(), 'zone-suspend', $this->zones[1]);
        $update = $this->startSecond($this->org(), 'zone-update', $this->zones[2], ['label' => 'Route C renommée']);
        $this->assertBlocked($suspend);
        $this->assertBlocked($update);

        self::assertTrue($this->outcome($first)['ok']);
        self::assertFalse($this->outcome($suspend)['ok'], 'déjà suspendue par le lot');
        self::assertTrue($this->outcome($update)['ok'], 'une modification descriptive reste possible sur une zone suspendue');
        self::assertSame(3, $this->auditCount('ZONE_SUSPENDED'));
        self::assertSame('Route C renommée', $this->zoneColumn($this->zones[2], 'label'));
    }

    public function testABulkValidityWaitsForAnIndividualConfigurationOfAZoneInTheSelection(): void
    {
        $first = $this->startHolding($this->org(), 'zone-validity', $this->zones[0], ['mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot('2026-12-09')]]);
        $second = $this->startSecond($this->org(), 'bulk-validity', $this->created->participationId, ['zoneIds' => $this->zones, 'mode' => 'EVENT_DURATION', 'confirm' => false]);
        $this->assertBlocked($second);

        self::assertTrue($this->outcome($first)['ok']);
        $secondResult = $this->outcome($second);

        self::assertFalse($secondResult['ok'], 'la zone 0 a désormais une configuration différente : confirmation exigée, lot refusé en bloc');
        self::assertSame('UNCONFIGURED', $this->zoneColumn($this->zones[1], 'validity_mode'), 'aucune autre zone n\'a été touchée');
    }

    // ── Deux communes ────────────────────────────────────────────────────────

    public function testBulkOperationsOfTwoMunicipalitiesDoNotBlockEachOther(): void
    {
        $zoneB = $this->zoneService()->create($this->as($this->w->adminB), $this->participationB, new CreateZoneCommand('Zone B', 'DISTRICT'))->zoneId;
        $first = $this->startHolding($this->org(), 'bulk-suspend', $this->created->participationId, $this->selection());
        $second = $this->startSecond($this->adminB(), 'bulk-suspend', $this->participationB, ['zoneIds' => [$zoneB, $this->proposedZoneB]]);
        $deadline = microtime(true) + 2.0;
        while (ParallelPhp::isRunning($second) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        self::assertFalse(ParallelPhp::isRunning($second), 'la commune B n\'attend pas le lot de la commune organisatrice');
        self::assertTrue($this->outcome($second)['ok']);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertSame(2, $this->auditCount('ZONE_BULK_OPERATION'));
    }
}
