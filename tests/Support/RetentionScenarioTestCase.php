<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeOutcome;
use App\Retention\Application\RetentionNoticeService;
use App\Retention\Application\SharedPurgeService;

/**
 * Scénario partagé des tests de purge (LOT 05.6.4) : l'événement du scénario d'archives (une demande, deux dossiers communaux approuvés, laissez-passer, lien, justificatif,
 * plaques), clos puis vieilli au-delà de la conservation, avec avis préalables envoyés puis antidatés. La purge réelle est activée pour la durée du test.
 */
abstract class RetentionScenarioTestCase extends ArchiveScenarioTestCase
{
    protected function setUp(): void
    {
        $_SERVER['RETENTION_PURGE_ENABLED'] = '1';
        ConfigurableStepObserver::reset();
        @unlink(__DIR__.'/../../var/retention_test/purge-register.jsonl');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['RETENTION_PURGE_ENABLED']);
        ConfigurableStepObserver::reset();
        parent::tearDown();
    }

    /** Clôture, vieillit de `$months` mois, envoie les avis et les antidate de `$noticeAgeDays` jours. */
    protected function dueForPurge(int $months = 13, int $noticeAgeDays = 31): void
    {
        if ('CLOSED' !== $this->em()->getConnection()->fetchOne('SELECT status FROM event_event')) {
            $this->close();
        }
        $this->sql('UPDATE event_event SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MONTH)', ['m' => (string) $months]);
        static::getContainer()->get(RetentionNoticeService::class)->run(true);
        $this->sql('UPDATE retention_purge_record SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY) WHERE kind = \'NOTICE\'', ['d' => (string) $noticeAgeDays]);
        $this->em()->clear();
    }

    protected function purge(string $dossierId, bool $execute = true): PurgeOutcome
    {
        $outcome = static::getContainer()->get(DossierPurgeService::class)->purge($dossierId, $execute, $this->managed($this->admin));
        $this->em()->clear();

        return $outcome;
    }

    protected function purgeShared(string $requestId): PurgeOutcome
    {
        $outcome = static::getContainer()->get(SharedPurgeService::class)->purge($requestId, true, $this->managed($this->admin));
        $this->em()->clear();

        return $outcome;
    }

    protected function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
    }

    /** @return array<string, int> effectifs de toutes les tables métier touchées par une purge */
    protected function snapshot(): array
    {
        $out = [];
        foreach (['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_decision', 'request_permit_request_zone', 'request_permit_request_attachment', 'request_idempotency_key', 'permit_permit', 'permit_authorization', 'permit_revision', 'permit_authorization_zone', 'permit_authorization_period', 'notification_outbox', 'notification_secret', 'archive_consultation', 'archive_legal_hold', 'archive_manager_designation', 'audit_log', 'retention_purge_record', 'event_event', 'event_participation', 'identity_user', 'municipality'] as $table) {
            $out[$table] = $this->rows($table);
        }

        return $out;
    }
}
