<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Identity\Domain\User;
use App\Ops\Application\LossFindings;
use App\Ops\Application\OpsSettings;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\ReplayReport;
use App\Retention\Domain\RestoreItem;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rejeu des effacements après une restauration (LOT 05.6.7, arbitrage A3) : jamais automatique, jamais planifié. Réalisé par un OPÉRATEUR CWS (super-administrateur actif)
 * EXCLUSIVEMENT sur instruction écrite de l'autorité compétente dont la RÉFÉRENCE est obligatoire et tracée ; CWS ne prend aucune décision juridique : il ré-exécute des effacements
 * DÉJÀ décidés et inscrits au registre externe.
 *
 * Garanties : simulation par défaut ; exécution seulement avec (1) l'empreinte du rapport PRÉSENTÉ (un état qui a changé est refusé), (2) la référence d'approbation, (3) une preuve
 * externe saine (registre présent et chaîné, points d'ancrage concordants), (4) `RETENTION_PURGE_ENABLED=1`, (5) un verrou d'exécution unique. Chaque dossier passe par le service
 * de purge en mode ANTICIPÉ : événement clos, absence de gel et absence d'une autre demande de droits ouverte sont RECONTRÔLÉS sous verrou au moment de l'exécution ; données partagées
 * seulement quand plus aucun dossier ne reste (règles multicommunales du 05.6.4). Une transaction par dossier ; idempotent ; reprenable. Aucune suppression d'événement : MANUEL.
 */
final class RetentionReplayService
{
    public const APPROVAL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/:-]{4,63}$/';

    public function __construct(
        private readonly RestoreChecker $checker,
        private readonly DossierPurgeService $dossiers,
        private readonly SharedPurgeService $shared,
        private readonly PurgeRegisterFile $register,
        private readonly RegisterAdopter $adopter,
        private readonly RetentionSettings $settings,
        private readonly OpsSettings $ops,
        private readonly LossFindings $findings,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed opérateur non habilité
     * @throws ReplayRefused       approbation, preuve, rapport ou exécution refusés
     * @throws PurgeDisabled       exécution demandée alors que l'interrupteur de purge vaut 0
     */
    public function run(User $operator, string $approvalRef, ?string $reportHash, bool $execute, ?string $externalAnchors = null): ReplayReport
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if (1 !== preg_match(self::APPROVAL_PATTERN, $approvalRef)) {
            throw new ReplayRefused('APPROVAL_REFERENCE_REQUIRED', 'Une référence d\'approbation écrite (5 à 64 caractères : lettres, chiffres, . _ / : -) est obligatoire.');
        }
        if ($execute && !$this->settings->purgeEnabled()) {
            throw PurgeDisabled::create();
        }
        $report = $this->checker->check($externalAnchors);
        if (!$report->proofSound()) {
            throw new ReplayRefused('PROOF_NOT_SOUND', 'Preuve externe absente, rompue ou incohérente ('.implode(', ', $report->proofProblems).') : aucun rejeu.');
        }
        $result = new ReplayReport();
        $result->plannedDossiers = \count($report->replayable(RestoreItem::REINTRODUCED_DOSSIER));
        $result->plannedShared = \count($report->replayable(RestoreItem::REINTRODUCED_SHARED));
        if (!$execute) {
            return $result;
        }
        if (null === $reportHash || '' === $reportHash || !hash_equals($report->hash, $reportHash)) {
            throw new ReplayRefused('REPORT_HASH_MISMATCH', 'L\'empreinte du rapport ne correspond pas à l\'état constaté : relancez le contrôle de restauration et présentez le nouveau rapport.');
        }
        $lock = $this->lock();
        try {
            $this->findings->record($report->items); // les constats de perte survivent au rejeu
            $result->executed = true;
            $batch = Uuid::v7();
            $result->batch = $batch->toRfc4122();
            $byId = [];
            foreach ($this->register->lines() as $line) {
                if (null !== $line && \is_string($line['id'] ?? null)) {
                    $byId[$line['id']] = $line;
                }
            }
            foreach ($report->replayable(RestoreItem::REINTRODUCED_DOSSIER) as $item) {
                $line = $byId[$item->recordId] ?? null;
                if (null !== $line && $this->replayDossier($operator, $item, $line, $batch, $result)) {
                    return $this->finish($operator, $approvalRef, $report->hash, $result);
                }
            }
            foreach ($report->replayable(RestoreItem::REINTRODUCED_SHARED) as $item) {
                $line = $byId[$item->recordId] ?? null;
                if (null !== $line && $this->replayShared($operator, $item, $line, $batch, $result)) {
                    return $this->finish($operator, $approvalRef, $report->hash, $result);
                }
            }

            return $this->finish($operator, $approvalRef, $report->hash, $result);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $line @return bool vrai si le traitement doit s'arrêter (gestionnaire fermé) */
    private function replayDossier(User $operator, RestoreItem $item, array $line, Uuid $batch, ReplayReport $result): bool
    {
        try {
            $origin = $this->checker->originRightsRequest(\is_string($line['batch'] ?? null) ? $line['batch'] : null);
            $outcome = $this->dossiers->purge($item->dossierId, true, $operator, $origin ?? $batch, early: true);
            if ('PURGED' === $outcome->status) {
                ++$result->dossiersReplayed;
                $this->append($outcome->record, $result);
                if (null !== $origin) {
                    $this->markRightsExecuted($origin);
                }
            } elseif ('ALREADY_DONE' === $outcome->status) {
                ++$result->alreadyDone;
            } else {
                ++$result->blocked;
                $result->reason($outcome->reason);

                return false;
            }
            if ($this->adopter->adopt($line)) {
                ++$result->adopted;
            }
        } catch (\Throwable) {
            ++$result->failed;
            if (!$this->entityManager->isOpen()) {
                $result->interrupted = true;

                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $line @return bool */
    private function replayShared(User $operator, RestoreItem $item, array $line, Uuid $batch, ReplayReport $result): bool
    {
        try {
            $outcome = $this->shared->purge($item->requestId, true, $operator, $batch);
            if ('PURGED' === $outcome->status) {
                ++$result->sharedReplayed;
                $this->append($outcome->record, $result);
            } elseif ('ALREADY_DONE' === $outcome->status) {
                ++$result->alreadyDone;
            } else {
                ++$result->blocked;
                $result->reason($outcome->reason);

                return false;
            }
            if ($this->adopter->adopt($line)) {
                ++$result->adopted;
            }
        } catch (\Throwable) {
            ++$result->failed;
            if (!$this->entityManager->isOpen()) {
                $result->interrupted = true;

                return true;
            }
        }

        return false;
    }

    private function append(?PurgeRecord $record, ReplayReport $result): void
    {
        if (null === $record) {
            return;
        }
        try {
            $this->register->append($record);
        } catch (\Throwable) {
            ++$result->registerFileFailures; // divergence signalée par le contrôle de réconciliation
        }
    }

    private function markRightsExecuted(Uuid $request): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'UPDATE rights_request SET executed_at = :now WHERE id = :id AND executed_at IS NULL AND NOT EXISTS (SELECT 1 FROM rights_request_dossier l WHERE l.rights_request_id = :id)',
            ['now' => $now, 'id' => $request->toBinary()],
        );
    }

    private function finish(User $operator, string $approvalRef, string $reportHash, ReplayReport $result): ReplayReport
    {
        if ($this->entityManager->isOpen()) {
            $this->transaction->run(function () use ($operator, $approvalRef, $reportHash, $result): void {
                $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::RetentionReplayExecuted, AuditSubjectType::Retention, Uuid::fromString($result->batch), null, AuditActor::user($operator->id()), null, [
                    'approval' => $approvalRef,
                    'report' => $reportHash,
                    'dossiers' => $result->dossiersReplayed,
                    'shared' => $result->sharedReplayed,
                    'blocked' => $result->blocked,
                    'failed' => $result->failed,
                ]));
            });
        }

        return $result;
    }

    /**
     * Verrou d'exécution unique (deux rejeux simultanés sont refusés, jamais mis en file).
     *
     * @return resource
     */
    private function lock()
    {
        $directory = $this->ops->dir().'/locks';
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new ReplayRefused('LOCK_UNAVAILABLE', 'Répertoire des verrous inaccessible.');
        }
        $handle = fopen($directory.'/retention-replay.lock', 'c');
        if (false === $handle || !flock($handle, \LOCK_EX | \LOCK_NB)) {
            throw new ReplayRefused('REPLAY_ALREADY_RUNNING', 'Un rejeu est déjà en cours.');
        }

        return $handle;
    }
}
