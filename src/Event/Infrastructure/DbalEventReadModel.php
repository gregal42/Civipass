<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Application\EventReadModel;
use App\Event\Application\Read\EventListItem;
use App\Event\Application\Read\EventParticipationDetails;
use App\Event\Application\Read\EventParticipationSummary;
use App\Event\Application\Read\InvitationView;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures SQL directes : chaque requête nomme ses colonnes et borne ses lignes au périmètre autorisé. Aucune entité,
 * aucun SELECT *, aucune colonne interne dans les projections de coordination.
 */
#[AsAlias(EventReadModel::class)]
final readonly class DbalEventReadModel implements EventReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function eventsOf(Uuid $municipalityId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id AS event_id, e.public_reference, e.name, e.status, e.starts_at, e.ends_at,'
            .' t.code AS type_code, t.label AS type_label, o.id AS organizer_id, o.name AS organizer_name, p.status AS my_status'
            .' FROM event_event e'
            .' JOIN event_type t ON t.id = e.type_id'
            .' JOIN municipality o ON o.id = e.organizer_municipality_id'
            .' LEFT JOIN event_participation p ON p.event_id = e.id AND p.municipality_id = :municipality'
            .' WHERE e.organizer_municipality_id = :municipality OR p.id IS NOT NULL'
            .' ORDER BY e.created_at DESC, e.id DESC LIMIT :limit',
            ['municipality' => $municipalityId->toBinary(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(fn (array $r): EventListItem => new EventListItem(
            self::uuid($r['event_id']),
            (string) $r['public_reference'],
            (string) $r['name'],
            EventStatus::from((string) $r['status']),
            self::date($r['starts_at']),
            null === $r['ends_at'] ? null : self::date($r['ends_at']),
            (string) $r['type_code'],
            (string) $r['type_label'],
            self::uuid($r['organizer_id']),
            (string) $r['organizer_name'],
            // L'organisatrice n'est pas « invitée » : son statut de participation n'est pas un choix de la liste.
            self::uuid($r['organizer_id']) === $municipalityId->toRfc4122() || null === $r['my_status'] ? null : ParticipationStatus::from((string) $r['my_status']),
        ), $rows);
    }

    public function coordinationOf(Uuid $eventId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id AS municipality_id, m.name AS municipality_name, p.status,'
            .' (p.municipality_id = e.organizer_municipality_id) AS organizer'
            .' FROM event_participation p'
            .' JOIN event_event e ON e.id = p.event_id'
            .' JOIN municipality m ON m.id = p.municipality_id'
            .' WHERE p.event_id = :event AND (p.municipality_id = e.organizer_municipality_id OR p.status IN (:active, :suspended))'
            .' ORDER BY organizer DESC, m.name, m.id',
            ['event' => $eventId->toBinary(), 'active' => ParticipationStatus::Active->value, 'suspended' => ParticipationStatus::Suspended->value],
        );

        return array_map(static fn (array $r): EventParticipationSummary => new EventParticipationSummary(
            self::uuid($r['municipality_id']),
            (string) $r['municipality_name'],
            ParticipationStatus::from((string) $r['status']),
            (bool) $r['organizer'],
        ), $rows);
    }

    public function participationsOf(Uuid $eventId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id AS participation_id, m.id AS municipality_id, m.name AS municipality_name, p.status,'
            .' (p.municipality_id = e.organizer_municipality_id) AS organizer, p.invitation_message, p.invited_at,'
            .' p.last_invitation_sent_at, p.activated_at, p.suspended_at, p.closed_at, p.suspension_reason'
            .' FROM event_participation p'
            .' JOIN event_event e ON e.id = p.event_id'
            .' JOIN municipality m ON m.id = p.municipality_id'
            .' WHERE p.event_id = :event'
            .' ORDER BY organizer DESC, m.name, m.id',
            ['event' => $eventId->toBinary()],
        );

        return array_map(static fn (array $r): EventParticipationDetails => new EventParticipationDetails(
            self::uuid($r['participation_id']),
            self::uuid($r['municipality_id']),
            (string) $r['municipality_name'],
            ParticipationStatus::from((string) $r['status']),
            (bool) $r['organizer'],
            null === $r['invitation_message'] ? null : (string) $r['invitation_message'],
            self::optionalDate($r['invited_at']),
            self::optionalDate($r['last_invitation_sent_at']),
            self::optionalDate($r['activated_at']),
            self::optionalDate($r['suspended_at']),
            self::optionalDate($r['closed_at']),
            null === $r['suspension_reason'] ? null : (string) $r['suspension_reason'],
        ), $rows);
    }

    public function invitationOf(Uuid $eventId, Uuid $municipalityId): ?InvitationView
    {
        $r = $this->connection->fetchAssociative(
            'SELECT e.public_reference, e.name, e.status AS event_status, e.starts_at, e.ends_at, t.label AS type_label,'
            .' o.name AS organizer_name, p.status, p.invitation_message, p.invited_at'
            .' FROM event_participation p'
            .' JOIN event_event e ON e.id = p.event_id'
            .' JOIN event_type t ON t.id = e.type_id'
            .' JOIN municipality o ON o.id = e.organizer_municipality_id'
            .' WHERE p.event_id = :event AND p.municipality_id = :municipality',
            ['event' => $eventId->toBinary(), 'municipality' => $municipalityId->toBinary()],
        );
        if (false === $r) {
            return null;
        }

        return new InvitationView(
            (string) $r['public_reference'],
            (string) $r['name'],
            (string) $r['type_label'],
            EventStatus::from((string) $r['event_status']),
            self::date($r['starts_at']),
            self::optionalDate($r['ends_at']),
            (string) $r['organizer_name'],
            ParticipationStatus::from((string) $r['status']),
            null === $r['invitation_message'] ? null : (string) $r['invitation_message'],
            self::optionalDate($r['invited_at']),
        );
    }

    private static function uuid(mixed $binary): string
    {
        return Uuid::fromBinary((string) $binary)->toRfc4122();
    }

    private static function date(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    private static function optionalDate(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : self::date($value);
    }
}
