<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Tests\Support\DormancyScenarioTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Commandes de dormance (LOT 05.6.5) : simulation par défaut, rapport sans donnée personnelle, aucune clôture. */
final class EventDormancyCommandTest extends DormancyScenarioTestCase
{
    public function testTheReportListsTheDormantEventWithoutPersonalDataAndTheAlertSimulatesByDefault(): void
    {
        $app = new Application(static::$kernel);
        $report = new CommandTester($app->find('app:event:dormancy-report'));
        self::assertSame(0, $report->execute([]));
        $out = $report->getDisplay();
        self::assertStringContainsString($this->event->publicReference()->value, $out);
        self::assertStringContainsString('ALERTABLE', $out);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $out);
        }
        self::assertStringNotContainsString('@', $out, 'aucune adresse');

        $alert = new CommandTester($app->find('app:event:dormancy-alert'));
        self::assertSame(0, $alert->execute([]));
        self::assertStringContainsString('SIMULATION', $alert->getDisplay());
        self::assertSame([], $this->sentEmails());
        self::assertSame(0, $this->rows('event_event', 'dormancy_alerted_at IS NOT NULL'));

        self::assertSame(0, $alert->execute(['--execute' => true]));
        self::assertStringContainsString('alertés=1', $alert->getDisplay());
        self::assertCount(1, $this->sentEmails());
        self::assertSame('ACTIVE', $this->eventRow()['status']);
    }
}
