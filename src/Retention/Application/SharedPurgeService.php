<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Identity\Domain\User;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\PurgeRecordKind;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Purge au niveau PARTAGÉ d'UNE demande (LOT 05.6.4, DM-120 D3) : identité, contact, adresse, plaques, justificatifs (lignes ET fichiers), laissez-passer, jetons, clés
 * d'idempotence, notifications et audit restants. N'agit QUE quand plus AUCUN dossier communal de la demande ne subsiste (contrôlé SOUS verrou de la ligne de demande) :
 * tant qu'une commune doit conserver son dossier, rien de partagé n'est supprimé. Fichiers supprimés D'ABORD (idempotent ; une panne laisse des lignes qui pointent
 * des fichiers absents, jamais des fichiers orphelins sans trace), puis lignes dans l'ordre des clés étrangères, dans UNE transaction ; reprenable.
 */
final class SharedPurgeService
{
    public const DOSSIERS_REMAIN = 'DOSSIERS_REMAIN';

    public function __construct(
        private readonly TransactionRunner $transaction,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly FileStorageInterface $storage,
        private readonly ClockInterface $clock,
        private readonly AuditTrail $audit,
        private readonly PurgeStepObserver $observer,
    ) {
    }

    public function purge(string $requestId, bool $execute, ?User $actor = null, ?Uuid $batchId = null): PurgeOutcome
    {
        if (!Uuid::isValid($requestId)) {
            return PurgeOutcome::skipped(PurgeOutcome::ALREADY_DONE, 'Demande inconnue.');
        }
        $id = Uuid::fromString($requestId);
        if (!$execute) {
            return $this->evaluate($id, false, null, null);
        }

        /** @var PurgeOutcome $outcome */
        $outcome = $this->transaction->run(fn (): PurgeOutcome => $this->evaluate($id, true, $actor, $batchId));

        return $outcome;
    }

    private function evaluate(Uuid $requestId, bool $execute, ?User $actor, ?Uuid $batchId): PurgeOutcome
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.id, r.event_id, e.public_reference, e.status FROM request_permit_request r JOIN event_event e ON e.id = r.event_id WHERE r.id = :r'.($execute ? ' FOR UPDATE OF r' : ''),
            ['r' => $requestId->toBinary()],
        );
        if (false === $row) {
            return PurgeOutcome::skipped(PurgeOutcome::ALREADY_DONE, 'Demande déjà purgée ou inconnue.');
        }
        // Lecture verrouillante : un dossier de la demande encore présent (ou en cours de purge dans une autre transaction) est vu.
        $dossiers = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality WHERE request_id = :r'.($execute ? ' FOR SHARE' : ''), ['r' => $requestId->toBinary()]);
        if ($dossiers > 0 || 'CLOSED' !== $row['status']) {
            return PurgeOutcome::skipped(PurgeOutcome::NOT_DUE, self::DOSSIERS_REMAIN);
        }
        $eventId = Uuid::fromBinary((string) $row['event_id']);
        $permit = $this->connection->fetchAssociative('SELECT id FROM permit_permit WHERE request_id = :r', ['r' => $requestId->toBinary()]);
        $permitId = false === $permit ? null : (string) $permit['id'];
        $subjects = null === $permitId ? [$requestId->toBinary()] : [$requestId->toBinary(), $permitId];
        $now = $this->clock->now();

        $attachments = $this->connection->fetchAllAssociative('SELECT storage_key FROM request_permit_request_attachment WHERE request_id = :r', ['r' => $requestId->toBinary()]);
        $counts = [];
        $files = 0;
        if ($execute) {
            // Fichiers D'ABORD, avant toute ligne : une suppression de fichier est idempotente (absence tolérée) et une reprise repart des lignes restantes.
            foreach ($attachments as $attachment) {
                $this->storage->delete((string) $attachment['storage_key']);
                ++$files;
            }
            $this->observer->afterStep('files');
        } else {
            $files = \count($attachments);
        }
        $outbox = null === $permitId ? [] : array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn('SELECT id FROM notification_outbox WHERE permit_id = :p', ['p' => $permitId]));

        $step = function (string $name, string $table, string $where, array $params, ?array $in = null) use (&$counts, $execute): void {
            $counts[$name] = $this->run($execute, $table, $where, $params, $in);
            if ($execute) {
                $this->observer->afterStep($name);
            }
        };
        $step('request_idempotency_key', 'request_idempotency_key', 'request_id = :r', ['r' => $requestId->toBinary()]);
        $step('notification_secret', 'notification_secret', 'outbox_id IN (:ids)', [], $outbox);
        $step('notification_outbox', 'notification_outbox', 'id IN (:ids)', [], $outbox);
        if (null !== $permitId) {
            $step('permit_access_token', 'permit_access_token', 'permit_id = :p', ['p' => $permitId]);
            $step('permit_verification_token', 'permit_verification_token', 'permit_id = :p', ['p' => $permitId]);
            $step('permit_vehicle', 'permit_vehicle', 'permit_id = :p', ['p' => $permitId]);
            $step('permit_permit', 'permit_permit', 'id = :p', ['p' => $permitId]);
        }
        $step('request_permit_request_vehicle', 'request_permit_request_vehicle', 'request_id = :r', ['r' => $requestId->toBinary()]);
        $step('request_permit_request_attachment', 'request_permit_request_attachment', 'request_id = :r', ['r' => $requestId->toBinary()]);
        $step('audit_log', 'audit_log', 'subject_type IN (\'PERMIT_REQUEST\', \'PERMIT\') AND subject_id IN (:ids)', [], $subjects);
        $step('request_permit_request', 'request_permit_request', 'id = :r', ['r' => $requestId->toBinary()]);

        if (!$execute) {
            return PurgeOutcome::simulated($counts, $files, $requestId->toRfc4122(), false);
        }
        $record = new PurgeRecord(PurgeRecordKind::Shared, $eventId, (string) $row['public_reference'], null, null, $requestId, $now, null, $actor?->id(), $counts, $files, null, $batchId);
        $this->entityManager->persist($record);
        $this->audit->record(new AuditEvent($now, AuditAction::RetentionSharedPurged, AuditSubjectType::Retention, $requestId, null, null === $actor ? AuditActor::system() : AuditActor::user($actor->id()), null, [
            'event' => $eventId->toRfc4122(),
            'counts' => $counts,
            'files' => $files,
        ], $batchId));
        $this->observer->afterStep('record');

        return new PurgeOutcome(PurgeOutcome::PURGED, '', $counts, $files, $requestId->toRfc4122(), false, $record);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>|null    $in
     */
    private function run(bool $execute, string $table, string $where, array $params, ?array $in): int
    {
        $types = [];
        if (null !== $in) {
            if ([] === $in) {
                return 0;
            }
            $params['ids'] = $in;
            $types['ids'] = ArrayParameterType::BINARY;
        }
        if (!$execute) {
            return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params, $types);
        }

        return (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $table, $where), $params, $types);
    }
}
