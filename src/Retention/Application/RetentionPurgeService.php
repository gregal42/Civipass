<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Archive\Application\ArchiveSettings;
use App\Identity\Domain\User;
use App\Retention\Domain\PurgeRecord;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrateur de la purge (LOT 05.6.4) : les dossiers éligibles, un par transaction (niveau commune), puis le niveau partagé de chaque demande dont plus aucun dossier ne
 * subsiste, puis une passe de REPRISE des demandes restées sans dossier après une interruption. Simulation par défaut ; l'exécution réelle exige l'interrupteur
 * `RETENTION_PURGE_ENABLED`. Une panne sur un dossier n'arrête pas les autres (sauf EntityManager fermé : arrêt propre) ; l'état est recalculé à chaque passage, donc une
 * relance reprend exactement où l'on s'est arrêté. Jamais appelé par une requête web.
 */
final class RetentionPurgeService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly DossierPurgeService $dossiers,
        private readonly SharedPurgeService $shared,
        private readonly PurgeRegisterFile $register,
        private readonly RetentionSettings $settings,
        private readonly ArchiveSettings $archive,
        private readonly ClockInterface $clock,
        private readonly RegisterAnchors $anchors,
    ) {
    }

    /**
     * @throws PurgeDisabled exécution réelle demandée alors que l'interrupteur est à 0
     */
    public function run(bool $execute, int $limit = 100, ?string $eventId = null, ?string $municipalityId = null, ?User $actor = null): RetentionRunReport
    {
        if ($execute && !$this->settings->purgeEnabled()) {
            throw PurgeDisabled::create();
        }
        $report = new RetentionRunReport();
        $batch = Uuid::v7();
        $now = $this->clock->now();
        $noticeDays = $this->settings->noticeDays();

        $where = '';
        $params = [];
        if (null !== $eventId && Uuid::isValid($eventId)) {
            $where .= ' AND e.id = :event';
            $params['event'] = Uuid::fromString($eventId)->toBinary();
        }
        if (null !== $municipalityId && Uuid::isValid($municipalityId)) {
            $where .= ' AND rm.municipality_id = :municipality';
            $params['municipality'] = Uuid::fromString($municipalityId)->toBinary();
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT rm.id AS dossier_id, e.closed_at, MIN(n.occurred_at) AS notice_at FROM request_permit_request_municipality rm'
            .' JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id'
            .' JOIN retention_purge_record n ON n.kind = \'NOTICE\' AND n.event_id = e.id AND n.municipality_id = rm.municipality_id'
            .' WHERE e.status = \'CLOSED\' AND e.closed_at IS NOT NULL'.$where
            .' AND NOT EXISTS (SELECT 1 FROM archive_legal_hold h WHERE h.request_municipality_id = rm.id AND h.active_marker = 1)'
            .' GROUP BY rm.id, e.closed_at ORDER BY e.closed_at, rm.id',
            $params,
        );
        foreach ($rows as $row) {
            if ($report->candidates >= $limit) {
                break;
            }
            $closedAt = new \DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC'));
            $noticeAt = new \DateTimeImmutable((string) $row['notice_at'], new \DateTimeZone('UTC'));
            if ($this->archive->purgeEligibleAt($closedAt) > $now || $noticeAt->modify(sprintf('+%d days', $noticeDays)) > $now) {
                continue; // préfiltre ; l'éligibilité est de toute façon redémontrée sous verrou
            }
            ++$report->candidates;
            $dossierId = Uuid::fromBinary((string) $row['dossier_id'])->toRfc4122();
            try {
                $outcome = $this->dossiers->purge($dossierId, $execute, $actor, $batch);
                $this->account($report, $outcome, $execute);
                if ($execute && PurgeOutcome::PURGED === $outcome->status) {
                    $this->append($report, $outcome->record);
                    if ($outcome->sharedDue && null !== $outcome->requestId) {
                        $this->purgeShared($report, $outcome->requestId, $actor, $batch);
                    }
                }
            } catch (\Throwable $e) {
                ++$report->failed;
                $report->failures[] = $e::class;
                if (!$this->entityManager->isOpen()) {
                    $report->interrupted = true;

                    return $report; // EntityManager fermé : arrêt propre, la relance reprend
                }
            }
        }

        // Reprise : demandes restées SANS aucun dossier (interruption entre les deux niveaux).
        foreach ($this->orphanRequests($eventId) as $requestId) {
            ++$report->candidates;
            try {
                $this->purgeShared($report, $requestId, $actor, $batch, $execute);
            } catch (\Throwable $e) {
                ++$report->failed;
                $report->failures[] = $e::class;
                if (!$this->entityManager->isOpen()) {
                    $report->interrupted = true;

                    return $report;
                }
            }
        }

        if ($execute) {
            $this->anchorAfterRun($report);
        }

        return $report;
    }

    /** Point d'ancrage après un passage qui a écrit le registre (au mieux : l'échec est rattrapé par la tâche horaire d'ancrage). */
    private function anchorAfterRun(RetentionRunReport $report): void
    {
        if (0 === $report->done + $report->sharedPurged) {
            return;
        }
        try {
            $this->anchors->emit();
        } catch (\Throwable) {
        }
    }

    private function purgeShared(RetentionRunReport $report, string $requestId, ?User $actor, Uuid $batch, bool $execute = true): void
    {
        $outcome = $this->shared->purge($requestId, $execute, $actor, $batch);
        if (PurgeOutcome::PURGED === $outcome->status || PurgeOutcome::SIMULATED === $outcome->status) {
            ++$report->sharedPurged;
            $report->addCounts($outcome->counts);
            $report->files += $outcome->files;
            $this->append($report, $outcome->record);
        } elseif (PurgeOutcome::ALREADY_DONE === $outcome->status) {
            ++$report->alreadyDone;
        } else {
            $report->reason($outcome->reason);
        }
    }

    private function account(RetentionRunReport $report, PurgeOutcome $outcome, bool $execute): void
    {
        switch ($outcome->status) {
            case PurgeOutcome::PURGED:
            case PurgeOutcome::SIMULATED:
                ++$report->done;
                $report->addCounts($outcome->counts);
                break;
            case PurgeOutcome::ALREADY_DONE:
                ++$report->alreadyDone;
                break;
            default:
                ++$report->notEligible;
                $report->reason($outcome->reason);
        }
    }

    /** La copie hors base est écrite APRÈS le commit SQL ; un échec est compté, la divergence est réparable (réconciliation). */
    private function append(RetentionRunReport $report, ?PurgeRecord $record): void
    {
        if (null === $record) {
            return;
        }
        try {
            $this->register->append($record);
        } catch (\Throwable) {
            ++$report->registerFileFailures;
        }
    }

    /** @return list<string> */
    private function orphanRequests(?string $eventId): array
    {
        $where = '';
        $params = [];
        if (null !== $eventId && Uuid::isValid($eventId)) {
            $where = ' AND e.id = :event';
            $params['event'] = Uuid::fromString($eventId)->toBinary();
        }

        return array_map(
            static fn (mixed $v): string => Uuid::fromBinary((string) $v)->toRfc4122(),
            $this->connection->fetchFirstColumn(
                'SELECT r.id FROM request_permit_request r JOIN event_event e ON e.id = r.event_id WHERE e.status = \'CLOSED\''.$where
                .' AND NOT EXISTS (SELECT 1 FROM request_permit_request_municipality rm WHERE rm.request_id = r.id) ORDER BY r.id LIMIT 200',
                $params,
            ),
        );
    }
}
