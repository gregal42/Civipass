<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Détection de la dormance (LOT 05.6.5, décision D3) : événements `ACTIVE` ou `SUSPENDED`, SANS date de fin, dont la dernière activité remonte à `EVENT_DORMANCY_DAYS` jours ou
 * plus. LECTURE SEULE : ne modifie ni statut, ni droit, ni date.
 *
 * Activité = la plus récente de : création, modification, activation, suspension de l'événement ; invitation, activation, modification d'une participation ; modification d'une zone
 * ou d'un créneau ; dépôt d'une demande ; décision ; création d'un laissez-passer ; révision d'un laissez-passer. La prise d'alerte et le report n'en sont PAS (écrits sans toucher aux dates).
 */
final class EventDormancyDetector
{
    private const ACTIVITY = <<<'SQL'
GREATEST(
  e.created_at, e.updated_at, COALESCE(e.activated_at, e.created_at), COALESCE(e.suspended_at, e.created_at),
  COALESCE((SELECT MAX(GREATEST(p.created_at, p.updated_at, COALESCE(p.invited_at, p.created_at), COALESCE(p.activated_at, p.created_at), COALESCE(p.suspended_at, p.created_at))) FROM event_participation p WHERE p.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(z.updated_at) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(v.updated_at) FROM event_zone_validity v JOIN event_zone z ON z.id = v.zone_id JOIN event_participation p ON p.id = z.participation_id WHERE p.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(r.submitted_at) FROM request_permit_request r WHERE r.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(d.decided_at) FROM request_permit_request_decision d JOIN request_permit_request_municipality rm ON rm.id = d.request_municipality_id JOIN request_permit_request r ON r.id = rm.request_id WHERE r.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(pp.created_at) FROM permit_permit pp WHERE pp.event_id = e.id), e.created_at),
  COALESCE((SELECT MAX(rv.occurred_at) FROM permit_revision rv JOIN permit_permit pp ON pp.id = rv.permit_id WHERE pp.event_id = e.id), e.created_at)
)
SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly DormancySettings $settings,
    ) {
    }

    /** @return list<DormantEvent> du plus ancien au plus récent ; `$eventId` limite à un événement */
    public function scan(?Uuid $eventId = null, int $limit = 500): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC')); // dates brutes SQL en UTC
        $threshold = $now->modify(sprintf('-%d days', $this->settings->days()));
        $params = ['threshold' => $threshold->format('Y-m-d H:i:s')];
        $where = '';
        if (null !== $eventId) {
            $where = ' AND e.id = :id';
            $params['id'] = $eventId->toBinary();
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.public_reference, e.name, e.organizer_municipality_id, e.dormancy_alerted_at, e.dormancy_snoozed_until, '.self::ACTIVITY.' AS last_activity FROM event_event e'
            .' WHERE e.status IN (\'ACTIVE\', \'SUSPENDED\') AND e.ends_at IS NULL'.$where.' HAVING last_activity <= :threshold ORDER BY last_activity, e.id LIMIT '.max(1, $limit),
            $params,
        );
        $utc = new \DateTimeZone('UTC');
        $events = [];
        foreach ($rows as $row) {
            $last = new \DateTimeImmutable((string) $row['last_activity'], $utc);
            $alerted = null === $row['dormancy_alerted_at'] ? null : new \DateTimeImmutable((string) $row['dormancy_alerted_at'], $utc);
            $snoozed = null === $row['dormancy_snoozed_until'] ? null : new \DateTimeImmutable((string) $row['dormancy_snoozed_until'], $utc);
            $id = Uuid::fromBinary((string) $row['id']);
            $events[] = new DormantEvent(
                $id->toRfc4122(),
                (string) $row['public_reference'],
                (string) $row['name'],
                Uuid::fromBinary((string) $row['organizer_municipality_id'])->toRfc4122(),
                $last,
                (int) $last->diff($now)->days,
                $this->state($id, $alerted, $snoozed, $now),
                $alerted,
                $snoozed,
            );
        }

        return $events;
    }

    public function forEvent(Uuid $eventId): ?DormantEvent
    {
        return $this->scan($eventId, 1)[0] ?? null;
    }

    private function state(Uuid $eventId, ?\DateTimeImmutable $alerted, ?\DateTimeImmutable $snoozed, \DateTimeImmutable $now): string
    {
        if (null !== $snoozed && $snoozed > $now) {
            return DormantEvent::SNOOZED;
        }
        if (null === $alerted) {
            return DormantEvent::ALERTABLE;
        }
        // Une prise d'alerte n'est un ENVOI que si une ligne d'audit « envoyée » la suit : sinon c'est une simple réservation (bail).
        $confirmed = false !== $this->connection->fetchOne(
            'SELECT 1 FROM audit_log WHERE subject_type = \'EVENT\' AND subject_id = :e AND action = \'DORMANCY_ALERT_SENT\' AND occurred_at >= :a LIMIT 1',
            ['e' => $eventId->toBinary(), 'a' => $alerted->format('Y-m-d H:i:s')],
        );
        if ($confirmed) {
            return $alerted <= $now->modify(sprintf('-%d days', $this->settings->reminderDays())) ? DormantEvent::ALERTABLE : DormantEvent::ALERTED_RECENTLY;
        }

        return $alerted <= $now->modify(sprintf('-%d minutes', $this->settings->leaseMinutes())) ? DormantEvent::RETRY : DormantEvent::LEASE_PENDING;
    }
}
