<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Dormancy\DormantEvent;
use App\Event\Application\Dormancy\DormancyRunReport;
use App\Event\Application\Dormancy\EventDormancyAlertService;
use App\Event\Application\Dormancy\EventDormancyDetector;

/**
 * Scénario partagé des tests de dormance (LOT 05.6.5) : l'événement du scénario d'archives (organisatrice et partenaire, administrateurs, organisateurs, instructeurs, demande
 * approuvée, laissez-passer), SANS date de fin, dont toute l'activité est reculée de 100 jours (seuil par défaut : 90).
 */
abstract class DormancyScenarioTestCase extends ArchiveScenarioTestCase
{
    /** Sources d'activité recensées : table, colonne, condition. */
    protected const SOURCES = [
        'événement' => 'UPDATE event_event SET updated_at = UTC_TIMESTAMP()',
        'participation' => 'UPDATE event_participation SET updated_at = UTC_TIMESTAMP()',
        'zone' => 'UPDATE event_zone SET updated_at = UTC_TIMESTAMP()',
        'demande' => 'UPDATE request_permit_request SET submitted_at = UTC_TIMESTAMP()',
        'décision' => 'UPDATE request_permit_request_decision SET decided_at = UTC_TIMESTAMP()',
        'laissez-passer' => 'UPDATE permit_permit SET created_at = UTC_TIMESTAMP()',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->age(100);
    }

    /** Recule TOUTE l'activité de l'événement de `$days` jours et lui retire sa date de fin. */
    protected function age(int $days): void
    {
        $x = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
        $p = ['x' => $x];
        $this->sql('UPDATE event_event SET ends_at = NULL, created_at = :x, updated_at = :x, activated_at = IF(activated_at IS NULL, NULL, :x), suspended_at = IF(suspended_at IS NULL, NULL, :x), dormancy_alerted_at = NULL, dormancy_snoozed_until = NULL', $p);
        $this->sql('UPDATE event_participation SET created_at = :x, updated_at = :x, invited_at = IF(invited_at IS NULL, NULL, :x), activated_at = IF(activated_at IS NULL, NULL, :x), suspended_at = IF(suspended_at IS NULL, NULL, :x)', $p);
        $this->sql('UPDATE event_zone SET created_at = :x, updated_at = :x', $p);
        $this->sql('UPDATE event_zone_validity SET created_at = :x, updated_at = :x', $p);
        $this->sql('UPDATE request_permit_request SET submitted_at = :x', $p);
        $this->sql('UPDATE request_permit_request_decision SET decided_at = :x', $p);
        $this->sql('UPDATE permit_permit SET created_at = :x', $p);
        $this->sql('UPDATE permit_revision SET occurred_at = :x', $p);
        $this->em()->clear();
    }

    /** @return list<DormantEvent> */
    protected function dormant(): array
    {
        return static::getContainer()->get(EventDormancyDetector::class)->scan();
    }

    protected function alerts(bool $execute = true): DormancyRunReport
    {
        $report = static::getContainer()->get(EventDormancyAlertService::class)->run($execute);
        $this->em()->clear();

        return $report;
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    protected function sentEmails(): array
    {
        $messages = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages() as $message) {
            $messages[] = $message;
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    protected function eventRow(): array
    {
        return $this->em()->getConnection()->fetchAssociative('SELECT status, ends_at, closed_at, updated_at, activated_at, suspended_at FROM event_event') ?: [];
    }

    protected function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
    }
}
