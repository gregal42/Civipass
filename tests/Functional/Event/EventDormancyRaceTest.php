<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Tests\Support\DormancyScenarioTestCase;
use App\Tests\Support\ParallelPhp;

/**
 * Concurrence RÉELLE (processus PHP sur MySQL) de l'alerte de dormance (LOT 05.6.5) : plusieurs passages simultanés n'envoient qu'UNE alerte par événement (prise atomique),
 * sans erreur technique ; un passage de reprise ultérieur n'en ajoute pas.
 */
final class EventDormancyRaceTest extends DormancyScenarioTestCase
{
    public function testSeveralSimultaneousRunsSendExactlyOneAlert(): void
    {
        $results = [];
        foreach (ParallelPhp::run('dormancy-race.php', [[], [], [], []]) as $raw) {
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            self::assertTrue($decoded['ok'], json_encode($decoded, \JSON_THROW_ON_ERROR).$raw['stderr']);
            $results[] = $decoded;
        }

        self::assertSame(1, array_sum(array_column($results, 'alerted')), 'une seule alerte pour quatre exécutions simultanées');
        self::assertSame(0, array_sum(array_column($results, 'failed')));
        self::assertSame(1, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
        self::assertSame(0, $this->alerts()->alerted, 'un passage ultérieur n\'ajoute rien');
        self::assertSame(1, $this->rows('audit_log', 'action = \'DORMANCY_ALERT_SENT\''));
        self::assertSame('ACTIVE', $this->eventRow()['status']);
    }
}
