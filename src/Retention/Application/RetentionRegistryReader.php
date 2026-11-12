<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Registre de conservation d'UNE commune (LOT 05.6.4), lecture seule, réservé à son `MUNICIPALITY_ADMIN` : jamais les lignes d'une autre commune ; identifiants,
 * dates et effectifs seulement, aucune donnée personnelle. Liste aussi les événements clos que la commune organise et leur éventuelle suppression.
 */
final class RetentionRegistryReader
{
    public function __construct(private readonly Connection $connection, private readonly ArchiveAccessPolicy $policy, private readonly EventDeletionService $deletion)
    {
    }

    /**
     * @return array{records: list<array{kind: string, event: string, occurredAt: \DateTimeImmutable, dueAt: ?\DateTimeImmutable, counts: array<string, int>}>, events: list<array{id: string, name: string, reference: string, blockers: array<string, int>}>}
     */
    public function forAdministrator(User $user, string $municipalityId): array
    {
        $membership = $this->policy->administrator($user, $municipalityId, false);
        $m = $membership->municipality()->id();
        $utc = new \DateTimeZone('UTC');
        $records = [];
        foreach ($this->connection->fetchAllAssociative('SELECT kind, event_reference, occurred_at, due_at, counts FROM retention_purge_record WHERE municipality_id = :m ORDER BY occurred_at DESC, id DESC LIMIT 200', ['m' => $m->toBinary()]) as $row) {
            $counts = null === $row['counts'] ? [] : (array) json_decode((string) $row['counts'], true);
            $records[] = ['kind' => (string) $row['kind'], 'event' => (string) $row['event_reference'], 'occurredAt' => new \DateTimeImmutable((string) $row['occurred_at'], $utc), 'dueAt' => null === $row['due_at'] ? null : new \DateTimeImmutable((string) $row['due_at'], $utc), 'counts' => array_map('intval', $counts)];
        }
        $events = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name, public_reference FROM event_event WHERE organizer_municipality_id = :m AND status = \'CLOSED\' ORDER BY closed_at, id LIMIT 100', ['m' => $m->toBinary()]) as $row) {
            $id = Uuid::fromBinary((string) $row['id'])->toRfc4122();
            $events[] = ['id' => $id, 'name' => (string) $row['name'], 'reference' => (string) $row['public_reference'], 'blockers' => array_filter($this->deletion->blockers($user, $municipalityId, $id))];
        }

        return ['records' => $records, 'events' => $events];
    }
}
