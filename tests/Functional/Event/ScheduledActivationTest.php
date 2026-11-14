<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventZoneService;
use App\Event\Application\Service\ScheduledEventActivation;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\MunicipalPagesTestCase;

/** Activation automatique à l'heure de début (DM-123) : conditions de l'activation manuelle, système comme acteur, jamais de force. */
final class ScheduledActivationTest extends MunicipalPagesTestCase
{
    /** @param list<mixed> $params */
    private function sql(string $sql, array $params = []): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, $params);
    }

    private function bin(string $eventId): string
    {
        return (string) hex2bin(str_replace('-', '', $eventId));
    }

    private function started(string $eventId, string $interval = '-1 hour'): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE event_event SET starts_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', strtotime($interval)), $this->bin($eventId)]);
    }

    private function eventStatus(string $eventId): string
    {
        return (string) $this->sql('SELECT status FROM event_event WHERE id = ?', [$this->bin($eventId)]);
    }

    private function makeReady(string $eventId): void
    {
        $admin = $this->user($this->w->adminO);
        $zone = (string) $this->sql(
            'SELECT LOWER(CONCAT(SUBSTR(HEX(z.id),1,8),\'-\',SUBSTR(HEX(z.id),9,4),\'-\',SUBSTR(HEX(z.id),13,4),\'-\',SUBSTR(HEX(z.id),17,4),\'-\',SUBSTR(HEX(z.id),21)))'
            .' FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = ? AND p.municipality_id = ?',
            [$this->bin($eventId), $this->w->org->id()->toBinary()],
        );
        $zones = static::getContainer()->get(EventZoneService::class);
        $zones->confirm($admin, $zone);
        $zones->configureValidity($admin, $zone, new ZoneValidityCommand('EVENT_DURATION'));
    }

    /** @return array{due: int, activated: int, notReady: int, skipped: int} */
    private function pass(bool $execute = true): array
    {
        $this->em()->clear();

        return static::getContainer()->get(ScheduledEventActivation::class)->run($execute);
    }

    public function testADueEventWithAReadyZoneIsActivatedBySystemAndAuditedOnce(): void
    {
        $this->makeReady($this->fire);
        $this->started($this->fire);

        $first = $this->pass();
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        self::assertSame(1, $first['activated']);
        self::assertSame('SYSTEM', $this->sql('SELECT origin FROM audit_log WHERE action = \'EVENT_ACTIVATED\' AND subject_id = ?', [$this->bin($this->fire)]));
        self::assertNull($this->sql('SELECT actor_id FROM audit_log WHERE action = \'EVENT_ACTIVATED\' AND subject_id = ?', [$this->bin($this->fire)]));
        self::assertStringContainsString('schedule', (string) $this->sql('SELECT new_value FROM audit_log WHERE action = \'EVENT_ACTIVATED\' AND subject_id = ?', [$this->bin($this->fire)]));

        $again = $this->pass();
        self::assertSame(0, $again['activated'], 'rejouable sans effet');
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM audit_log WHERE action = \'EVENT_ACTIVATED\' AND subject_id = ?', [$this->bin($this->fire)]));
    }

    public function testADueEventWithoutReadyZoneStaysDraftAndThePageSaysSoThenIsActivatedOnceReady(): void
    {
        $this->started($this->fire);
        $report = $this->pass();
        self::assertSame('DRAFT', $this->eventStatus($this->fire));
        self::assertSame(1, $report['notReady']);

        $this->loginAs($this->w->adminO);
        $html = $this->get($this->eventUrl($this->w->org, $this->fire));
        self::assertStringContainsString('L’heure de début est passée', $html);

        $this->makeReady($this->fire);
        self::assertSame(1, $this->pass()['activated']);
        self::assertSame('ACTIVE', $this->eventStatus($this->fire));
        $this->loginAs($this->w->adminO);
        self::assertStringNotContainsString('L’heure de début est passée', $this->get($this->eventUrl($this->w->org, $this->fire)));
    }

    public function testFutureEndedSuspendedAndDryRunAreNeverActivated(): void
    {
        $this->makeReady($this->fire);
        // Futur : rien (l'événement « fire » démarre le 01/12/2026).
        self::assertSame(0, $this->pass()['due']);

        // Dry-run : compte sans écrire.
        $this->started($this->fire);
        $dry = $this->pass(false);
        self::assertSame(1, $dry['activated']);
        self::assertSame('DRAFT', $this->eventStatus($this->fire));

        // Terminé : fin passée, jamais activé.
        $this->em()->getConnection()->executeStatement('UPDATE event_event SET ends_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', strtotime('-10 minutes')), $this->bin($this->fire)]);
        self::assertSame(0, $this->pass()['due']);
        $this->em()->getConnection()->executeStatement('UPDATE event_event SET ends_at = NULL WHERE id = ?', [$this->bin($this->fire)]);

        // Commune organisatrice suspendue : l'activation manuelle serait refusée, l'automatique aussi.
        $this->em()->getConnection()->executeStatement('UPDATE municipality SET status = ? WHERE id = ?', [MunicipalityStatus::Suspended->value, $this->w->org->id()->toBinary()]);
        $suspended = $this->pass();
        self::assertSame(0, $suspended['due'] + $suspended['activated']);
        self::assertSame('DRAFT', $this->eventStatus($this->fire));
    }

    public function testAnEventSuspendedByAHumanIsNotReactivated(): void
    {
        $this->makeReady($this->fire);
        $this->started($this->fire);
        self::assertSame(1, $this->pass()['activated']);
        static::getContainer()->get(EventLifecycleService::class)->suspend($this->user($this->w->adminO), $this->fire, 'Alerte');

        self::assertSame(0, $this->pass()['due']);
        self::assertSame('SUSPENDED', $this->eventStatus($this->fire));
    }
}
