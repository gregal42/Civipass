<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Support\ValidityRevision;
use App\Event\Domain\Validity\ValidityMode;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\ZoneConcurrencyTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Empreinte de la validité sous OPÉRATIONS RÉELLEMENT CONCURRENTES (processus PHP sur MySQL réel). Deux administrateurs ont affiché la
 * même configuration. Le premier TIENT ses verrous (attente avant le commit) ; le second démarre pendant ce temps, doit attendre le
 * verrou de la zone, puis lit — dans SA transaction, sous verrou — la configuration validée par le premier : empreinte périmée,
 * conflit, rien d'écrit, modification du premier intacte. Aucune fusion, aucune nouvelle tentative.
 */
#[Group('requires-event-schema')]
final class EventZoneValidityRevisionConcurrencyTest extends ZoneConcurrencyTestCase
{
    private function revision(string $zone): string
    {
        $mode = ValidityMode::from((string) $this->zoneColumn($zone, 'validity_mode'));
        $ids = array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_zone_validity WHERE zone_id = UNHEX(?) AND deleted_at IS NULL', [$this->hex($zone)]));

        return ValidityRevision::of($mode, $ids);
    }

    /** @return list<string> */
    private function activeDates(string $zone): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT DATE_FORMAT(start_date, \'%Y-%m-%d\') FROM event_zone_validity WHERE zone_id = UNHEX(?) AND deleted_at IS NULL ORDER BY start_date', [$this->hex($zone)]));
    }

    /**
     * @return array<string, mixed>
     */
    private function specific(string $date, ?string $expected): array
    {
        return ['mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot($date)], 'expectedRevision' => $expected];
    }

    public function testTwoConcurrentAdministratorsOnTheSameConfigurationOnlyTheFirstIsWritten(): void
    {
        $zone = $this->created->proposedZoneId;
        $seen = $this->revision($zone);

        $first = $this->startHolding($this->org(), 'zone-validity', $zone, $this->specific('2026-12-02', $seen));
        $second = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-03', $seen));
        $this->assertBlocked($second); // le second attend le verrou de la zone : il n'a encore rien lu de périmé ni écrit
        $firstResult = $this->outcome($first);
        $secondResult = $this->outcome($second);

        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertFalse($secondResult['ok'], json_encode($secondResult));
        self::assertSame(OperationConflict::class, $secondResult['error']);
        self::assertSame(ConflictCause::StaleState->value, $secondResult['cause']);
        self::assertSame(['2026-12-02'], $this->activeDates($zone), 'la modification du premier n’est pas écrasée');
        self::assertSame(1, $this->auditCount('ZONE_VALIDITY_SLOT_CREATED'), 'une seule écriture, un seul audit');
        self::assertSame(0, $this->auditCount('ZONE_VALIDITY_SLOT_DELETED'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'le second n’a rien écrit');
    }

    public function testTheSecondWriterAcceptsTheCurrentRevisionOnceTheFirstHasCommitted(): void
    {
        $zone = $this->created->proposedZoneId;
        $first = $this->startHolding($this->org(), 'zone-validity', $zone, $this->specific('2026-12-02', $this->revision($zone)));
        self::assertTrue($this->outcome($first)['ok']);

        $second = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-03', $this->revision($zone))); // relecture explicite
        $secondResult = $this->outcome($second);

        self::assertTrue($secondResult['ok'], json_encode($secondResult));
        self::assertSame(['2026-12-03'], $this->activeDates($zone));
    }

    public function testAnUnrelatedZoneOperationDoesNotCauseAFalseConflict(): void
    {
        $zone = $this->created->proposedZoneId;
        $seen = $this->revision($zone);

        $first = $this->startHolding($this->org(), 'zone-update', $zone, ['label' => 'Libellé modifié pendant la configuration']);
        $second = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-02', $seen));
        $this->assertBlocked($second);
        $firstResult = $this->outcome($first);
        $secondResult = $this->outcome($second);

        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertTrue($secondResult['ok'], json_encode($secondResult)); // le libellé ne fait pas partie de la configuration de validité
        self::assertSame(['2026-12-02'], $this->activeDates($zone));
        self::assertSame('Libellé modifié pendant la configuration', $this->zoneColumn($zone, 'label'));
    }

    public function testAConcurrentModeChangeWithoutSlotsIsDetectedToo(): void
    {
        $zone = $this->created->proposedZoneId;
        $seen = $this->revision($zone); // « à configurer » : aucun créneau, mode non configuré

        $first = $this->startHolding($this->org(), 'zone-validity', $zone, ['mode' => 'EVENT_DURATION', 'expectedRevision' => $seen]);
        $second = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-03', $seen));
        $this->assertBlocked($second);
        $firstResult = $this->outcome($first);
        $secondResult = $this->outcome($second);

        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertFalse($secondResult['ok'], json_encode($secondResult));
        self::assertSame(ConflictCause::StaleState->value, $secondResult['cause']);
        self::assertSame('EVENT_DURATION', $this->zoneColumn($zone, 'validity_mode'), 'le choix du premier n’est pas écrasé');
        self::assertSame([], $this->activeDates($zone));
    }

    public function testThreeConcurrentWritersFromTheSameConfigurationExactlyOneWins(): void
    {
        $zone = $this->created->proposedZoneId;
        $seen = $this->revision($zone);

        $first = $this->startHolding($this->org(), 'zone-validity', $zone, $this->specific('2026-12-02', $seen));
        $second = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-03', $seen));
        $third = $this->startSecond($this->org(), 'zone-validity', $zone, $this->specific('2026-12-04', $seen));
        $outcomes = [$this->outcome($first), $this->outcome($second), $this->outcome($third)];

        self::assertSame(1, \count(array_filter($outcomes, static fn (array $o): bool => true === $o['ok'])), json_encode($outcomes));
        foreach (array_filter($outcomes, static fn (array $o): bool => false === $o['ok']) as $refused) {
            self::assertSame(ConflictCause::StaleState->value, $refused['cause'], json_encode($refused));
        }
        self::assertCount(1, $this->activeDates($zone));
        self::assertSame(1, $this->auditCount('ZONE_VALIDITY_SLOT_CREATED'));
    }
}
