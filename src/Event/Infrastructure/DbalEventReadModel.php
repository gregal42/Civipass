<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Application\EventReadModel;
use App\Event\Application\Read\EventListItem;
use App\Event\Application\Read\EventListPage;
use App\Event\Application\Read\EventListQuery;
use App\Event\Application\Read\EventParticipantHistoryEntry;
use App\Event\Application\Read\EventParticipationDetails;
use App\Event\Application\Read\EventParticipationSummary;
use App\Event\Application\Read\EventTypeOption;
use App\Event\Application\Read\InvitationCandidate;
use App\Event\Application\Read\InvitationCandidateState;
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
            'SELECT e.id AS event_id, e.public_reference, e.name, e.status, e.starts_at, e.ends_at, e.timezone,'
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
            (string) $r['timezone'],
        ), $rows);
    }

    public function searchEventsOf(Uuid $municipalityId, EventListQuery $query): EventListPage
    {
        $from = ' FROM event_event e'
            .' JOIN event_type t ON t.id = e.type_id'
            .' JOIN municipality o ON o.id = e.organizer_municipality_id'
            .' LEFT JOIN event_participation p ON p.event_id = e.id AND p.municipality_id = :municipality';
        $where = ' WHERE (e.organizer_municipality_id = :municipality OR p.id IS NOT NULL)';
        $parameters = ['municipality' => $municipalityId->toBinary()];
        if (null !== $query->search) {
            // Les jokers saisis par l'utilisateur sont neutralisés : la recherche est littérale.
            $where .= " AND (e.name LIKE :search ESCAPE '!' OR e.public_reference LIKE :search ESCAPE '!')";
            $parameters['search'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search).'%';
        }
        if (null !== $query->status) {
            $where .= ' AND e.status = :status';
            $parameters['status'] = $query->status->value;
        }
        if (null !== $query->typeCode) {
            $where .= ' AND t.code = :type';
            $parameters['type'] = $query->typeCode;
        }

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*)'.$from.$where, $parameters);
        $page = new EventListPage([], $total, $query->page, $query->perPage); // borne la page demandée à la dernière page
        $direction = 'asc' === $query->direction ? 'ASC' : 'DESC'; // liste blanche : jamais la saisie dans le SQL
        $rows = 0 === $total ? [] : $this->connection->fetchAllAssociative(
            'SELECT e.id AS event_id, e.public_reference, e.name, e.status, e.starts_at, e.ends_at, e.timezone,'
            .' t.code AS type_code, t.label AS type_label, o.id AS organizer_id, o.name AS organizer_name, p.status AS my_status'
            .$from.$where
            .' ORDER BY e.starts_at '.$direction.', e.id '.$direction.' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => $query->perPage, 'offset' => ($page->page - 1) * $query->perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new EventListPage(array_map(fn (array $r): EventListItem => $this->listItem($r, $municipalityId), $rows), $total, $query->page, $query->perPage);
    }

    public function searchMunicipalitiesForInvitation(Uuid $eventId, string $term, int $limit): array
    {
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id AS municipality_id, m.name, m.insee_code, p.status AS participation_status'
            .' FROM municipality m'
            .' JOIN event_event e ON e.id = :event'
            .' LEFT JOIN event_participation p ON p.event_id = e.id AND p.municipality_id = m.id'
            ." WHERE m.status = 'ACTIVE' AND m.id <> e.organizer_municipality_id"
            ." AND (m.name LIKE :contains ESCAPE '!' OR m.insee_code LIKE :prefix ESCAPE '!')"
            .' ORDER BY m.name, m.id LIMIT :limit',
            ['event' => $eventId->toBinary(), 'contains' => '%'.$like.'%', 'prefix' => $like.'%', 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): InvitationCandidate => new InvitationCandidate(
            self::uuid($r['municipality_id']),
            (string) $r['name'],
            (string) $r['insee_code'],
            match (true) {
                null === $r['participation_status'] => InvitationCandidateState::Available,
                ParticipationStatus::Invited->value === $r['participation_status'] => InvitationCandidateState::Invited,
                ParticipationStatus::Closed->value === $r['participation_status'] => InvitationCandidateState::Closed,
                default => InvitationCandidateState::Participating,
            },
        ), $rows);
    }

    public function eventTypes(): array
    {
        return array_map(
            static fn (array $r): EventTypeOption => new EventTypeOption((string) $r['code'], (string) $r['label']),
            $this->connection->fetchAllAssociative('SELECT code, label FROM event_type ORDER BY sort_order, code'),
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private function listItem(array $r, Uuid $municipalityId): EventListItem
    {
        $organizerId = self::uuid($r['organizer_id']);

        return new EventListItem(
            self::uuid($r['event_id']),
            (string) $r['public_reference'],
            (string) $r['name'],
            EventStatus::from((string) $r['status']),
            self::date($r['starts_at']),
            null === $r['ends_at'] ? null : self::date($r['ends_at']),
            (string) $r['type_code'],
            (string) $r['type_label'],
            $organizerId,
            (string) $r['organizer_name'],
            $organizerId === $municipalityId->toRfc4122() || null === $r['my_status'] ? null : ParticipationStatus::from((string) $r['my_status']),
            (string) $r['timezone'],
        );
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

    public function historyOf(Uuid $eventId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id AS municipality_id, m.name AS municipality_name, (p.municipality_id = e.organizer_municipality_id) AS organizer'
            .' FROM event_participation p'
            .' JOIN event_event e ON e.id = p.event_id'
            .' JOIN municipality m ON m.id = p.municipality_id'
            .' WHERE p.event_id = :event AND p.activated_at IS NOT NULL'
            .' ORDER BY organizer DESC, m.name, m.id',
            ['event' => $eventId->toBinary()],
        );

        return array_map(static fn (array $r): EventParticipantHistoryEntry => new EventParticipantHistoryEntry(
            self::uuid($r['municipality_id']),
            (string) $r['municipality_name'],
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
