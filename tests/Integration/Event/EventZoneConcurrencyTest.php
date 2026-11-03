<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateZoneCommand;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ZoneConcurrencyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence des zones sur MySQL réel (processus PHP). Pour maîtriser l'ordre, la première opération TIENT ses verrous
 * (attente avant le commit, SleepingAuditTrail) et signale par un fichier qu'elle les tient ; la seconde démarre alors,
 * doit attendre, puis voit l'état complet de la première (jamais un état partiel ni périmé).
 */
#[Group('requires-event-schema')]
final class EventZoneConcurrencyTest extends ZoneConcurrencyTestCase
{
    // ── Zone en cours, puis activation ───────────────────────────────────────

    /**
     * Seule zone de l'événement : confirmée et configurée, sauf ce que l'opération concurrente va changer.
     *
     * @return iterable<string, array{string, bool, bool, bool}>
     */
    public static function zoneOperationFirst(): iterable
    {
        // action, zone confirmée au départ, zone configurée au départ, l'activation doit aboutir
        yield 'suspension de la seule zone prête' => ['zone-suspend', true, true, false];
        yield 'suppression de la seule zone prête' => ['zone-delete', true, true, false];
        yield 'confirmation de la seule zone non confirmée' => ['zone-confirm', false, true, true];
        yield 'configuration de la seule zone non configurée' => ['zone-validity', true, false, true];
        yield 'reconfiguration en périodes (zone déjà prête)' => ['zone-validity-periods', true, true, true];
    }

    #[DataProvider('zoneOperationFirst')]
    public function testActivationWaitsForARunningZoneOperationAndReadsItsCommittedState(string $action, bool $confirmed, bool $configured, bool $activationSucceeds): void
    {
        $zone = $this->created->proposedZoneId;
        if ($confirmed) {
            $this->zoneService()->confirm($this->as($this->w->adminO), $zone);
        }
        if ($configured) {
            $this->zoneService()->configureValidity($this->as($this->w->adminO), $zone, $this->eventDuration());
        }
        $payload = match ($action) {
            'zone-validity' => ['mode' => 'EVENT_DURATION'],
            'zone-validity-periods' => ['mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot()]],
            default => null,
        };
        $actionName = 'zone-validity-periods' === $action ? 'zone-validity' : $action;
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $first = $this->startHolding($this->org(), $actionName, $zone, $payload);
        $second = $this->startSecond($this->org(), 'activate', $this->created->eventId);
        $this->assertBlocked($second);
        $firstResult = $this->outcome($first);
        $secondResult = $this->outcome($second);

        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertSame($activationSucceeds, $secondResult['ok'], json_encode($secondResult));
        self::assertSame($activationSucceeds ? 'ACTIVE' : 'DRAFT', $this->eventStatus());
        self::assertSame($activationSucceeds ? 1 : 0, $this->auditCount('EVENT_ACTIVATED'));
        self::assertGreaterThan($audits, $this->rows('SELECT COUNT(*) FROM audit_log'), 'l\'audit de la zone est validé');
    }

    // ── Activation en cours, puis zone ───────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function zoneOperationSecond(): iterable
    {
        yield 'suspension' => ['zone-suspend'];
        yield 'suppression' => ['zone-delete'];
        yield 'modification du libellé' => ['zone-update'];
        yield 'configuration des créneaux' => ['zone-validity'];
    }

    #[DataProvider('zoneOperationSecond')]
    public function testAZoneOperationWaitsForARunningActivationThenAppliesToTheActiveEvent(string $action): void
    {
        $this->makeEventActivatable();
        $zone = $this->created->proposedZoneId;
        $payload = match ($action) {
            'zone-update' => ['label' => 'Libellé modifié après activation'],
            'zone-validity' => ['mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot()]],
            default => null,
        };

        $first = $this->startHolding($this->org(), 'activate', $this->created->eventId);
        $second = $this->startSecond($this->org(), $action, $zone, $payload);
        $this->assertBlocked($second);
        $firstResult = $this->outcome($first);
        $secondResult = $this->outcome($second);

        self::assertTrue($firstResult['ok'], json_encode($firstResult));
        self::assertTrue($secondResult['ok'], json_encode($secondResult));
        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame(1, $this->auditCount('EVENT_ACTIVATED'));
        $expected = ['zone-suspend' => 'ZONE_SUSPENDED', 'zone-delete' => 'ZONE_DELETED', 'zone-update' => 'ZONE_UPDATED', 'zone-validity' => 'ZONE_VALIDITY_SLOT_CREATED'][$action];
        self::assertSame(1, $this->auditCount($expected), 'l\'opération de zone est auditée une fois, après l\'activation');
        // Les identifiants d'audit (UUID v7) sont créés sous verrou : l'audit de la zone suit celui de l'activation.
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log z WHERE z.action = '".$expected."' AND z.id > (SELECT a.id FROM audit_log a WHERE a.action = 'EVENT_ACTIVATED')"));
    }

    // ── Clôture et zone ──────────────────────────────────────────────────────

    public function testAZoneUpdateWaitsForARunningClosureAndIsThenRefused(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Libellé initial', 'STREET'))->zoneId;

        $first = $this->startHolding($this->org(), 'close-event', $this->created->eventId);
        $second = $this->startSecond($this->org(), 'zone-update', $zone, ['label' => 'Libellé tardif']);
        $this->assertBlocked($second);
        self::assertTrue($this->outcome($first)['ok']);
        $secondResult = $this->outcome($second);

        self::assertFalse($secondResult['ok']);
        self::assertSame('Libellé initial', $this->zoneColumn($zone, 'label'), 'aucune zone modifiée après la clôture');
        self::assertSame(0, $this->auditCount('ZONE_UPDATED'));
        self::assertSame('CLOSED', $this->eventStatus());
    }

    public function testAClosureWaitsForARunningZoneUpdateAndClosesEverything(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Libellé initial', 'STREET'))->zoneId;

        $first = $this->startHolding($this->org(), 'zone-update', $zone, ['label' => 'Libellé validé avant clôture']);
        $second = $this->startSecond($this->org(), 'close-event', $this->created->eventId);
        $this->assertBlocked($second);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        self::assertSame('Libellé validé avant clôture', $this->zoneColumn($zone, 'label'));
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"));
        self::assertSame(1, $this->auditCount('ZONE_UPDATED'));
    }

    // ── Même zone, même participation, communes différentes ──────────────────

    public function testTwoUpdatesOfTheSameZoneAreSerializedAndBothAudited(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Libellé initial', 'STREET'))->zoneId;

        $first = $this->startHolding($this->org(), 'zone-update', $zone, ['label' => 'Libellé A']);
        $second = $this->startSecond($this->org(), 'zone-update', $zone, ['label' => 'Libellé B']);
        $this->assertBlocked($second);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        self::assertSame('Libellé B', $this->zoneColumn($zone, 'label'), 'la seconde passe après la première');
        self::assertSame(2, $this->auditCount('ZONE_UPDATED'));
    }

    public function testTwoConcurrentConfirmationsAndTwoConcurrentIdenticalConfigurationsChangeOnlyOnce(): void
    {
        $zone = $this->created->proposedZoneId;

        $results = array_map($this->outcome(...), [$this->startSecond($this->org(), 'zone-confirm', $zone), $this->startSecond($this->org(), 'zone-confirm', $zone)]);
        self::assertTrue($results[0]['ok'] && $results[1]['ok']);
        self::assertSame(1, $this->auditCount('ZONE_CONFIRMED'));

        $payload = ['mode' => 'SPECIFIC_PERIODS', 'slots' => [$this->slot()]];
        $results = array_map($this->outcome(...), [$this->startSecond($this->org(), 'zone-validity', $zone, $payload), $this->startSecond($this->org(), 'zone-validity', $zone, $payload)]);
        self::assertTrue($results[0]['ok'] && $results[1]['ok']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'DM-088 : le second remplacement est identique, rien n\'est écrit');
        self::assertSame(1, $this->auditCount('ZONE_VALIDITY_SLOT_CREATED'));
    }

    public function testTwoMunicipalitiesEditTheirOwnZonesWithoutBlockingEachOther(): void
    {
        $first = $this->startHolding($this->org(), 'zone-confirm', $this->created->proposedZoneId);
        $second = $this->startSecond($this->adminB(), 'zone-confirm', $this->proposedZoneB);
        $deadline = microtime(true) + 2.0;
        while (ParallelPhp::isRunning($second) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        self::assertFalse(ParallelPhp::isRunning($second), 'C10 : la commune B n\'attend pas la commune organisatrice (événement S compatible, participations distinctes)');
        self::assertTrue($this->outcome($second)['ok']);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertSame(2, $this->auditCount('ZONE_CONFIRMED'));
    }

    public function testASuspensionOfTheSameZoneBySeveralProcessesIsAppliedOnce(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Route', 'STREET'))->zoneId;

        $results = array_map($this->outcome(...), [$this->startSecond($this->org(), 'zone-suspend', $zone), $this->startSecond($this->org(), 'zone-suspend', $zone), $this->startSecond($this->org(), 'zone-suspend', $zone)]);

        self::assertCount(1, array_filter($results, static fn (array $r): bool => true === $r['ok']), json_encode($results));
        self::assertSame(1, $this->auditCount('ZONE_SUSPENDED'));
        self::assertSame('SUSPENDED', $this->zoneColumn($zone, 'status'));
    }

    // ── Horodatage des audits (DT-038) ───────────────────────────────────────

    /**
     * @return \DateTimeImmutable instant UTC d'écriture de l'audit
     */
    private function auditTime(string $action, string $order): \DateTimeImmutable
    {
        $value = $this->em->getConnection()->fetchOne("SELECT occurred_at FROM audit_log WHERE action = '".$action."' ORDER BY id ".$order.' LIMIT 1');

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    public function testTheAuditTimestampIsTakenAfterTheLockWaitNotBeforeIt(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Libellé initial', 'STREET'))->zoneId;

        $first = $this->startHolding($this->org(), 'zone-update', $zone, ['label' => 'Libellé A']);
        $heldAt = time(); // la première opération tient son verrou depuis (au plus) cet instant, pendant 2,5 s
        $second = $this->startSecond($this->org(), 'zone-update', $zone, ['label' => 'Libellé B']);
        $this->assertBlocked($second);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        $firstAt = $this->auditTime('ZONE_UPDATED', 'ASC');
        $secondAt = $this->auditTime('ZONE_UPDATED', 'DESC');
        self::assertGreaterThanOrEqual($heldAt + 2, $secondAt->getTimestamp(), 'la seconde opération a attendu ≥ 2 s : son horodatage est celui de l\'écriture, pas celui de son démarrage');
        self::assertGreaterThan($firstAt->getTimestamp(), $secondAt->getTimestamp());
        self::assertGreaterThan(
            (string) $this->em->getConnection()->fetchOne("SELECT LOWER(HEX(id)) FROM audit_log WHERE action = 'ZONE_UPDATED' ORDER BY id ASC LIMIT 1"),
            (string) $this->em->getConnection()->fetchOne("SELECT LOWER(HEX(id)) FROM audit_log WHERE action = 'ZONE_UPDATED' ORDER BY id DESC LIMIT 1"),
            'l\'identifiant d\'audit reste la référence d\'ordonnancement',
        );
        // La date de mise à jour de la zone suit la même horloge que l'audit.
        self::assertSame($secondAt->format('Y-m-d H:i:s'), $this->zoneColumn($zone, 'updated_at'));
    }

    public function testTheLifecycleTimestampIsAlsoTakenAfterTheLockWait(): void
    {
        $zone = $this->zoneService()->create($this->as($this->w->adminO), $this->created->participationId, new CreateZoneCommand('Libellé initial', 'STREET'))->zoneId;

        $first = $this->startHolding($this->org(), 'zone-update', $zone, ['label' => 'Libellé A']);
        $heldAt = time();
        $second = $this->startSecond($this->org(), 'close-event', $this->created->eventId);
        $this->assertBlocked($second);
        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        self::assertGreaterThanOrEqual($heldAt + 2, $this->auditTime('EVENT_CLOSED', 'DESC')->getTimestamp());
        self::assertGreaterThanOrEqual($heldAt + 2, $this->auditTime('PARTICIPATION_CLOSED', 'DESC')->getTimestamp());
    }
}
