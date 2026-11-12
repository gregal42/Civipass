<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\PurgeRecordKind;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Suppression DÉFINITIVE d'un événement (phase 4, LOT 05.6.4, DM-120) : action EXPLICITE du `MUNICIPALITY_ADMIN` de la commune ORGANISATRICE, avec saisie de la référence
 * publique. Conditions relues SOUS verrou de l'événement : événement CLOS ; AUCUN dossier ni demande ni laissez-passer ne subsiste (tous purgés, ou événement sans demande).
 * Suppression ordonnée (habilitations d'archive et de coordination, invitations, validités, zones, participations, événement) et de l'audit des sujets événement ; une
 * ligne de registre et une ligne d'audit subsistent. Comptes, communes, types et référentiels sont INTACTS ; le moindre résidu qui référence l'événement annule tout
 * (clés `RESTRICT`). Le refus d'un non-administrateur de l'organisatrice est le 404 uniforme.
 */
final class EventDeletionService
{
    public function __construct(
        private readonly TransactionRunner $transaction,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ArchiveAccessPolicy $policy,
        private readonly EventRepository $events,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly PurgeStepObserver $observer,
        private readonly PurgeRegisterFile $register,
    ) {
    }

    /**
     * @return array<string, int> blocages : ce qui subsiste et empêche la suppression
     *
     * @throws OperationNotAllowed
     */
    public function blockers(User $actor, string $municipalityId, string $eventId): array
    {
        $event = $this->authorizedEvent($actor, $municipalityId, $eventId, false);

        return $this->remaining($event->id());
    }

    /**
     * @throws OperationNotAllowed refus uniforme
     * @throws DomainRuleViolation référence erronée ou données subsistantes
     */
    public function delete(User $actor, string $municipalityId, string $eventId, string $typedReference): PurgeRecord
    {
        $event = $this->authorizedEvent($actor, $municipalityId, $eventId, true);
        if (strtoupper(trim($typedReference)) !== strtoupper($event->publicReference()->value)) {
            throw new DomainRuleViolation('La référence saisie ne correspond pas à celle de l\'événement : rien n\'est supprimé.');
        }
        $id = $event->id();

        /** @var PurgeRecord $record */
        $record = $this->transaction->run(function () use ($actor, $id, $municipalityId): PurgeRecord {
            $row = $this->connection->fetchAssociative('SELECT id, status, public_reference FROM event_event WHERE id = :e FOR UPDATE', ['e' => $id->toBinary()]);
            if (false === $row || 'CLOSED' !== $row['status']) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $remaining = array_filter($this->remaining($id));
            if ([] !== $remaining) {
                throw new DomainRuleViolation('Des données subsistent : '.implode(', ', array_map(static fn (string $k, int $n): string => sprintf('%s : %d', $k, $n), array_keys($remaining), $remaining)).'. L\'événement ne peut être supprimé qu\'après la purge de tous ses dossiers.');
            }
            $counts = [];
            $step = function (string $name, string $sql, array $params, array $types = []) use (&$counts): void {
                $counts[$name] = (int) $this->connection->executeStatement($sql, $params, $types);
                $this->observer->afterStep($name);
            };
            $participations = $this->ids('SELECT id FROM event_participation WHERE event_id = :e', ['e' => $id->toBinary()]);
            $zones = [] === $participations ? [] : $this->idsIn('SELECT id FROM event_zone WHERE participation_id IN (:ids)', $participations);
            $validities = [] === $zones ? [] : $this->idsIn('SELECT id FROM event_zone_validity WHERE zone_id IN (:ids)', $zones);
            $designations = [] === $participations ? [] : $this->idsIn('SELECT id FROM archive_manager_designation WHERE participation_id IN (:ids)', $participations);
            $grants = $this->ids('SELECT id FROM archive_statistics_coordination WHERE event_id = :e', ['e' => $id->toBinary()]);
            $links = [] === $participations ? [] : $this->idsIn('SELECT id FROM onboarding_invitation_link WHERE participation_id IN (:ids)', $participations);
            $in = ArrayParameterType::BINARY;

            $audit = static fn (string $type, array $ids): array => [] === $ids ? ['', []] : ['DELETE FROM audit_log WHERE subject_type = \''.$type.'\' AND subject_id IN (:ids)', $ids];
            foreach ([['audit_log_archive_designation', 'ARCHIVE_DESIGNATION', $designations], ['audit_log_statistics_coordination', 'STATISTICS_COORDINATION', $grants], ['audit_log_invitation_link', 'INVITATION_LINK', $links], ['audit_log_zone_validity', 'EVENT_ZONE_VALIDITY', $validities], ['audit_log_zone', 'EVENT_ZONE', $zones], ['audit_log_participation', 'EVENT_PARTICIPATION', $participations]] as [$name, $type, $ids]) {
                [$sql, $list] = $audit($type, $ids);
                $counts[$name] = '' === $sql ? 0 : (int) $this->connection->executeStatement($sql, ['ids' => $list], ['ids' => $in]);
            }
            $this->observer->afterStep('audit_log_children');
            if ([] !== $designations) {
                $step('archive_manager_designation', 'DELETE FROM archive_manager_designation WHERE id IN (:ids)', ['ids' => $designations], ['ids' => $in]);
            }
            $step('archive_statistics_coordination', 'DELETE FROM archive_statistics_coordination WHERE event_id = :e', ['e' => $id->toBinary()]);
            if ([] !== $links) {
                // Les liens se référencent entre eux (lien parent) : la référence est levée avant la suppression, jamais l'inverse.
                $this->connection->executeStatement('UPDATE onboarding_invitation_link SET parent_link_id = NULL WHERE id IN (:ids)', ['ids' => $links], ['ids' => $in]);
                $step('onboarding_invitation_link', 'DELETE FROM onboarding_invitation_link WHERE id IN (:ids)', ['ids' => $links], ['ids' => $in]);
            }
            if ([] !== $validities) {
                $step('event_zone_validity', 'DELETE FROM event_zone_validity WHERE id IN (:ids)', ['ids' => $validities], ['ids' => $in]);
            }
            if ([] !== $zones) {
                $step('event_zone', 'DELETE FROM event_zone WHERE id IN (:ids)', ['ids' => $zones], ['ids' => $in]);
            }
            $counts['audit_log_event'] = (int) $this->connection->executeStatement('DELETE FROM audit_log WHERE subject_type = \'EVENT\' AND subject_id = :e', ['e' => $id->toBinary()]);
            $step('event_participation', 'DELETE FROM event_participation WHERE event_id = :e', ['e' => $id->toBinary()]);
            $step('event_event', 'DELETE FROM event_event WHERE id = :e', ['e' => $id->toBinary()]);

            $now = $this->clock->now();
            $record = new PurgeRecord(PurgeRecordKind::EventDeleted, $id, (string) $row['public_reference'], Uuid::fromString($municipalityId), null, null, $now, null, $actor->id(), $counts, 0, null, null);
            $this->entityManager->persist($record);
            $this->audit->record(new AuditEvent($now, AuditAction::RetentionEventDeleted, AuditSubjectType::Retention, $id, Uuid::fromString($municipalityId), AuditActor::user($actor->id()), null, ['counts' => $counts]));
            $this->observer->afterStep('record');

            return $record;
        });

        // Registre hors base : écrit APRÈS la validation SQL (non atomique) ; un échec est rattrapé par `app:retention:register-check --repair`.
        try {
            $this->register->append($record);
        } catch (\Throwable) {
        }

        return $record;
    }

    /** @return array<string, int> */
    private function remaining(Uuid $eventId): array
    {
        $e = ['e' => $eventId->toBinary()];

        return [
            'dossiers' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id WHERE r.event_id = :e', $e),
            'demandes' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request WHERE event_id = :e', $e),
            'laissez-passer' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_permit WHERE event_id = :e', $e),
        ];
    }

    private function authorizedEvent(User $actor, string $municipalityId, string $eventId, bool $write): \App\Event\Domain\Event
    {
        $membership = $this->policy->administrator($actor, $municipalityId, $write);
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event || !$event->isOrganizedBy($membership->municipality()) || !$event->status()->isClosed()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $event;
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
     * @param list<string> $list
     *
     * @return list<string>
     */
    private function idsIn(string $sql, array $list): array
    {
        return array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn($sql, ['ids' => $list], ['ids' => ArrayParameterType::BINARY]));
    }
}
