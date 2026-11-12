<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Archive\Application\ArchiveSettings;
use App\Event\Domain\ClosureStatisticsSummary;
use App\Event\Domain\Event;
use App\Identity\Domain\User;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\PurgeRecordKind;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
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
 * Purge au niveau COMMUNE d'UN dossier communal (demande × commune), LOT 05.6.4, DM-120 D3, règle 8. UNE transaction : verrou de la ligne du dossier, ÉLIGIBILITÉ
 * redémontrée SOUS verrou (événement clos, durée de conservation atteinte, aucun gel actif, préavis émis depuis assez longtemps) — jamais une purge dont l'éligibilité n'est pas
 * démontrée ; suppressions ordonnées (tables enfants d'abord, clés étrangères `RESTRICT` conservées : un résidu qui référence encore le dossier ANNULE tout) ; ligne de
 * registre et ligne d'audit conservées ; compteurs exacts réduits à leur forme publiée à la purge du DERNIER dossier de l'événement ; désignations d'archive supprimées au
 * dernier dossier de la commune. Idempotent (un dossier déjà purgé est ignoré), reprenable (une transaction par dossier, état recalculé à chaque passage). Une COMMUNE ne
 * supprime jamais une donnée partagée : le niveau partagé n'agit que quand plus AUCUN dossier de la demande ne subsiste (`SharedPurgeService`).
 */
final class DossierPurgeService
{
    public const NOT_CLOSED = 'EVENT_NOT_CLOSED';
    public const RETENTION_NOT_REACHED = 'RETENTION_NOT_REACHED';
    public const LEGAL_HOLD = 'LEGAL_HOLD';
    public const NO_NOTICE = 'NO_NOTICE';
    public const NOTICE_TOO_RECENT = 'NOTICE_TOO_RECENT';
    /** Une demande de droits non clôturée porte sur ce dossier (LOT 05.6.6) : la purge ne détruit jamais les données qu'une personne demande à consulter. */
    public const RIGHTS_REQUEST_OPEN = 'RIGHTS_REQUEST_OPEN';

    public function __construct(
        private readonly TransactionRunner $transaction,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly RetentionSettings $settings,
        private readonly ArchiveSettings $archive,
        private readonly AuditTrail $audit,
        private readonly PurgeStepObserver $observer,
    ) {
    }

    /**
     * @param bool $execute faux : SIMULATION (comptes, aucune écriture, aucun verrou)
     */
    /**
     * @param bool $early effacement ANTICIPÉ accordé par une demande de droits (LOT 05.6.6, `$batchId` = la demande) : seuls l'échéance et l'avis préalable sont écartés ; événement
     *                    clos, absence de gel et absence d'une AUTRE demande de droits ouverte restent exigés (jamais contournés)
     */
    public function purge(string $dossierId, bool $execute, ?User $actor = null, ?Uuid $batchId = null, bool $early = false): PurgeOutcome
    {
        if (!Uuid::isValid($dossierId)) {
            return PurgeOutcome::skipped(PurgeOutcome::ALREADY_DONE, 'Dossier inconnu.');
        }
        $id = Uuid::fromString($dossierId);
        if (!$execute) {
            return $this->evaluate($id, false, null, null, $early);
        }

        /** @var PurgeOutcome $outcome */
        $outcome = $this->transaction->run(fn (): PurgeOutcome => $this->evaluate($id, true, $actor, $batchId, $early));

        return $outcome;
    }

    private function evaluate(Uuid $dossierId, bool $execute, ?User $actor, ?Uuid $batchId, bool $early): PurgeOutcome
    {
        $lock = $execute ? ' FOR UPDATE OF rm' : '';
        $row = $this->connection->fetchAssociative(
            'SELECT rm.id, rm.request_id, rm.municipality_id, rm.participation_id, r.event_id, e.status, e.closed_at, e.public_reference'
            .' FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id WHERE rm.id = :d'.$lock,
            ['d' => $dossierId->toBinary()],
        );
        if (false === $row) {
            return PurgeOutcome::skipped(PurgeOutcome::ALREADY_DONE, 'Dossier déjà purgé ou inconnu.');
        }
        $requestId = Uuid::fromBinary((string) $row['request_id']);
        $municipalityId = Uuid::fromBinary((string) $row['municipality_id']);
        $participationId = Uuid::fromBinary((string) $row['participation_id']);
        $eventId = Uuid::fromBinary((string) $row['event_id']);
        $now = $this->clock->now();

        $reason = $this->ineligibility($row, $dossierId, $eventId, $municipalityId, $now, $execute, $early, $early ? $batchId : null);
        if (null !== $reason) {
            return PurgeOutcome::skipped(PurgeOutcome::NOT_ELIGIBLE, $reason);
        }

        // Identifiants des données de CETTE commune (jamais celles d'une autre).
        $permit = $this->connection->fetchAssociative('SELECT id FROM permit_permit WHERE request_id = :r', ['r' => $requestId->toBinary()]);
        $permitId = false === $permit ? null : (string) $permit['id'];
        $authorizations = null === $permitId ? [] : $this->ids('SELECT id FROM permit_authorization WHERE permit_id = :p AND municipality_id = :m', ['p' => $permitId, 'm' => $municipalityId->toBinary()]);
        $revisions = [];
        if (null !== $permitId) {
            $revisions = [] === $authorizations
                ? $this->ids('SELECT id FROM permit_revision WHERE permit_id = :p AND municipality_id = :m', ['p' => $permitId, 'm' => $municipalityId->toBinary()])
                : $this->idsIn('SELECT id FROM permit_revision WHERE permit_id = :p AND (municipality_id = :m OR authorization_id IN (:a))', ['p' => $permitId, 'm' => $municipalityId->toBinary()], 'a', $authorizations);
        }
        $outbox = null === $permitId || [] === $authorizations ? [] : $this->idsIn('SELECT id FROM notification_outbox WHERE permit_id = :p AND authorization_id IN (:a)', ['p' => $permitId], 'a', $authorizations);
        $decisions = $this->ids('SELECT id FROM request_permit_request_decision WHERE request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $holds = $this->ids('SELECT id FROM archive_legal_hold WHERE request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $auditSubjects = null === $permitId ? [$requestId->toBinary()] : [$requestId->toBinary(), $permitId];

        $counts = [];
        $step = function (string $name, string $table, string $where, array $params, ?array $in = null) use (&$counts, $execute): void {
            $counts[$name] = $this->run($execute, $table, $where, $params, $in);
            if ($execute) {
                $this->observer->afterStep($name);
            }
        };
        $step('notification_secret', 'notification_secret', 'outbox_id IN (:ids)', [], $outbox);
        $step('notification_outbox', 'notification_outbox', 'id IN (:ids)', [], $outbox);
        $step('permit_authorization_period', 'permit_authorization_period', 'authorization_id IN (:ids)', [], $authorizations);
        $step('permit_revision_vehicle', 'permit_revision_vehicle', 'revision_id IN (:ids)', [], $revisions);
        $step('permit_revision', 'permit_revision', 'id IN (:ids)', [], $revisions);
        $step('permit_authorization_zone', 'permit_authorization_zone', 'authorization_id IN (:ids)', [], $authorizations);
        $step('permit_authorization', 'permit_authorization', 'id IN (:ids)', [], $authorizations);
        $step('request_permit_request_zone', 'request_permit_request_zone', 'request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        // C14 (arbitrage R1, option 2) : la première décision du laissez-passer est mise à NULL ET supprimée dans CETTE transaction ; la provenance perdue n'est lue nulle part.
        $counts['permit_first_decision_nulled'] = $this->nullFirstDecision($execute, $decisions);
        if ($execute) {
            $this->observer->afterStep('permit_first_decision_nulled');
        }
        $step('request_permit_request_decision', 'request_permit_request_decision', 'id IN (:ids)', [], $decisions);
        $step('archive_consultation', 'archive_consultation', 'request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $step('archive_legal_hold', 'archive_legal_hold', 'request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $step('audit_log_legal_hold', 'audit_log', 'subject_type = \'LEGAL_HOLD\' AND subject_id IN (:ids)', [], $holds);
        // Audit de CETTE commune sur la demande et le laissez-passer : identifiants collectés AVANT (aucune clé étrangère). Les lignes sans commune et celles des autres
        // communes appartiennent au niveau partagé.
        $step('audit_log', 'audit_log', 'subject_type IN (\'PERMIT_REQUEST\', \'PERMIT\') AND municipality_id = :m AND subject_id IN (:ids)', ['m' => $municipalityId->toBinary()], $auditSubjects);
        $step('archive_rectification_mention', 'archive_rectification_mention', 'request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $step('rights_request_dossier', 'rights_request_dossier', 'request_municipality_id = :d', ['d' => $dossierId->toBinary()]);
        $step('request_permit_request_municipality', 'request_permit_request_municipality', 'id = :d', ['d' => $dossierId->toBinary()]);

        // Après la suppression de la ligne du dossier (en simulation : sans elle, on déduit ce qu'il resterait).
        $self = $execute ? 0 : 1;
        $remainingRequest = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality WHERE request_id = :r', ['r' => $requestId->toBinary()]) - $self;
        $remainingParticipation = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality WHERE participation_id = :p', ['p' => $participationId->toBinary()]) - $self;
        $remainingEvent = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id WHERE r.event_id = :e', ['e' => $eventId->toBinary()]) - $self;

        if (0 === $remainingParticipation) { // plus aucun dossier de la commune pour cet événement : plus rien à lire, la désignation d'archive n'a plus d'objet
            $designations = $this->ids('SELECT id FROM archive_manager_designation WHERE participation_id = :p', ['p' => $participationId->toBinary()]);
            $step('audit_log_archive_designation', 'audit_log', 'subject_type = \'ARCHIVE_DESIGNATION\' AND subject_id IN (:ids)', [], $designations);
            $step('archive_manager_designation', 'archive_manager_designation', 'participation_id = :p', ['p' => $participationId->toBinary()]);
        }
        if (0 === $remainingEvent) { // dernier dossier de l'événement : compteurs exacts remplacés par l'instantané publié (L5-37)
            $counts['closure_statistics_reduced'] = $this->reduceStatistics($execute, $eventId);
            if ($execute) {
                $this->observer->afterStep('closure_statistics_reduced');
            }
        }

        $sharedDue = 0 === $remainingRequest;
        if (!$execute) {
            return PurgeOutcome::simulated($counts, 0, $requestId->toRfc4122(), $sharedDue);
        }

        $record = new PurgeRecord(PurgeRecordKind::Dossier, $eventId, (string) $row['public_reference'], $municipalityId, $dossierId, $requestId, $now, null, $actor?->id(), $counts, 0, null, $batchId);
        $this->entityManager->persist($record);
        $this->audit->record(new AuditEvent($now, AuditAction::RetentionDossierPurged, AuditSubjectType::Retention, $dossierId, $municipalityId, null === $actor ? AuditActor::system() : AuditActor::user($actor->id()), null, [
            'event' => $eventId->toRfc4122(),
            'request' => $requestId->toRfc4122(),
            'counts' => $counts,
        ], $batchId));
        $this->observer->afterStep('record');

        return new PurgeOutcome(PurgeOutcome::PURGED, '', $counts, 0, $requestId->toRfc4122(), $sharedDue, $record);
    }

    /**
     * Éligibilité DÉMONTRÉE ; `null` si le dossier peut être purgé, sinon le motif. Relue sous verrou à chaque purge.
     *
     * @param array<string, mixed> $row
     */
    private function ineligibility(array $row, Uuid $dossierId, Uuid $eventId, Uuid $municipalityId, \DateTimeImmutable $now, bool $lock, bool $early = false, ?Uuid $ownRightsRequest = null): ?string
    {
        if ('CLOSED' !== $row['status'] || null === $row['closed_at']) {
            return self::NOT_CLOSED;
        }
        $closedAt = new \DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC'));
        if (!$early && $this->archive->purgeEligibleAt($closedAt) > $now) {
            return self::RETENTION_NOT_REACHED;
        }
        // Lecture verrouillante : un gel posé concurremment (qui verrouille la même ligne de dossier) est vu ; jamais l'instantané d'une transaction plus ancienne.
        $hold = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM archive_legal_hold WHERE request_municipality_id = :d AND active_marker = 1'.($lock ? ' FOR SHARE' : ''), ['d' => $dossierId->toBinary()]);
        if ($hold > 0) {
            return self::LEGAL_HOLD;
        }
        $open = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM rights_request_dossier l JOIN rights_request q ON q.id = l.rights_request_id WHERE l.request_municipality_id = :d AND q.status <> \'CLOSED\''.(null === $ownRightsRequest ? '' : ' AND q.id <> :own').($lock ? ' FOR SHARE' : ''),
            ['d' => $dossierId->toBinary()] + (null === $ownRightsRequest ? [] : ['own' => $ownRightsRequest->toBinary()]),
        );
        if ($open > 0) {
            return self::RIGHTS_REQUEST_OPEN;
        }
        if ($early) {
            return null; // effacement anticipé : ni avis préalable, ni échéance (gel, clôture et autres demandes de droits ont été contrôlés ci-dessus)
        }
        $notice = $this->connection->fetchOne('SELECT MIN(occurred_at) FROM retention_purge_record WHERE kind = \'NOTICE\' AND event_id = :e AND municipality_id = :m', ['e' => $eventId->toBinary(), 'm' => $municipalityId->toBinary()]);
        if (!\is_string($notice)) {
            return self::NO_NOTICE;
        }
        $noticeAt = new \DateTimeImmutable($notice, new \DateTimeZone('UTC'));
        if ($noticeAt->modify(sprintf('+%d days', $this->settings->noticeDays())) > $now) {
            return self::NOTICE_TOO_RECENT;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>|null    $in     liste de clés binaires pour `:ids` (vide : aucune ligne, aucune requête)
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

    /** @param list<string> $decisions */
    private function nullFirstDecision(bool $execute, array $decisions): int
    {
        if ([] === $decisions) {
            return 0;
        }
        $params = ['ids' => $decisions];
        $types = ['ids' => ArrayParameterType::BINARY];
        if (!$execute) {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_permit WHERE first_decision_id IN (:ids)', $params, $types);
        }

        return (int) $this->connection->executeStatement('UPDATE permit_permit SET first_decision_id = NULL WHERE first_decision_id IN (:ids)', $params, $types);
    }

    private function reduceStatistics(bool $execute, Uuid $eventId): int
    {
        $this->entityManager->clear();
        $event = $this->entityManager->find(Event::class, $eventId);
        $exact = $event?->closureStatistics();
        if (null === $event || null === $exact) {
            return 0; // déjà réduits, ou jamais calculés (événement clos avant M1)
        }
        if ($execute) {
            $event->reduceClosureStatistics(ClosureStatisticsSummary::fromStatistics($exact, $this->settings->statisticsThreshold()));
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function ids(string $sql, array $params): array
    {
        return array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn($sql, $params));
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $list
     *
     * @return list<string>
     */
    private function idsIn(string $sql, array $params, string $name, array $list): array
    {
        $params[$name] = $list;

        return array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn($sql, $params, [$name => ArrayParameterType::BINARY]));
    }
}
