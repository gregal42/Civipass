<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use App\Rights\Domain\RightsDecision;
use App\Rights\Domain\RightsRequestKind;
use App\Rights\Domain\RightsRequestStatus;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture des demandes de droits d'UNE commune (LOT 05.6.6), réservée à son `MUNICIPALITY_ADMIN`. Références, dates, codes et effectifs seulement : jamais l'identité d'une
 * personne ni le contenu d'un dossier (l'export est la seule voie, et elle est tracée).
 */
final class RightsRequestReader
{
    public function __construct(
        private readonly ArchiveAccessPolicy $policy,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly RightsRequestStore $store,
        private readonly RightsSettings $settings,
    ) {
    }

    /** @return list<array{id: string, municipality: string, reference: string, kind: RightsRequestKind, status: RightsRequestStatus, receivedAt: \DateTimeImmutable, dueAt: \DateTimeImmutable, overdue: bool, decision: ?RightsDecision, dossiers: int}> */
    public function list(User $actor, string $municipalityId): array
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: false)->municipality()->id();

        return $this->rows($municipality, null);
    }

    /** @return list<array{id: string, municipality: string, reference: string, kind: RightsRequestKind, status: RightsRequestStatus, receivedAt: \DateTimeImmutable, dueAt: \DateTimeImmutable, overdue: bool, decision: ?RightsDecision, dossiers: int}> */
    public function overdue(?Uuid $municipalityId = null): array
    {
        return array_values(array_filter($this->rows($municipalityId, null), static fn (array $r): bool => $r['overdue']));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OperationNotAllowed refus uniforme
     */
    public function detail(User $actor, string $municipalityId, string $requestId): array
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: false)->municipality()->id();
        $row = $this->store->find($requestId, $municipality) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $id = Uuid::fromBinary((string) $row['id']);
        $utc = new \DateTimeZone('UTC');
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable((string) $v, $utc);
        $dossiers = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT rm.id, r.public_reference, e.name AS event_name, e.status AS event_status,'
            .' (SELECT COUNT(*) FROM archive_legal_hold h WHERE h.request_municipality_id = rm.id AND h.active_marker = 1) AS hold,'
            .' (SELECT COUNT(*) FROM archive_rectification_mention m WHERE m.request_municipality_id = rm.id AND m.rights_request_id = :id) AS mentions'
            .' FROM rights_request_dossier l JOIN request_permit_request_municipality rm ON rm.id = l.request_municipality_id JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id'
            .' WHERE l.rights_request_id = :id AND rm.municipality_id = :m ORDER BY r.public_reference',
            ['id' => $id->toBinary(), 'm' => $municipality->toBinary()],
        ) as $d) {
            $dossiers[] = ['id' => Uuid::fromBinary((string) $d['id'])->toRfc4122(), 'reference' => (string) $d['public_reference'], 'event' => (string) $d['event_name'], 'eventClosed' => 'CLOSED' === $d['event_status'], 'hold' => (int) $d['hold'] > 0, 'mentions' => (int) $d['mentions']];
        }
        $now = RightsRequestStore::utc($this->clock->now());
        $kind = RightsRequestKind::from((string) $row['kind']);
        $status = RightsRequestStatus::from((string) $row['status']);
        $decision = null === $row['decision'] ? null : RightsDecision::from((string) $row['decision']);
        $until = $date($row['export_available_until']);
        $due = $date($row['extended_until']) ?? $date($row['due_at']);
        $exportOpen = RightsRequestKind::Access === $kind && RightsRequestStatus::Decided === $status && RightsDecision::Granted === $decision && null !== $until && $until >= $now
            && (int) $row['export_downloads'] < $this->settings->exportMaxDownloads() && [] !== $dossiers;

        return [
            'id' => $id->toRfc4122(),
            'reference' => (string) $row['public_reference'],
            'kind' => $kind,
            'status' => $status,
            'decision' => $decision,
            'refusalReason' => $row['refusal_reason'],
            'receivedAt' => $date($row['received_at']),
            'dueAt' => $due,
            'extended' => null !== $row['extended_until'],
            'overdue' => RightsRequestStatus::Closed !== $status && null !== $due && $due < $now,
            'identityMethod' => $row['identity_method'],
            'identityVerifiedAt' => $date($row['identity_verified_at']),
            'decidedAt' => $date($row['decided_at']),
            'executedAt' => $date($row['executed_at']),
            'closedAt' => $date($row['closed_at']),
            'conservationCheckedAt' => $date($row['conservation_checked_at']),
            'dossierCount' => (int) $row['dossier_count'],
            'dossiers' => $dossiers,
            'exportOpen' => $exportOpen,
            'exportUntil' => $until,
            'exportRemaining' => max(0, $this->settings->exportMaxDownloads() - (int) $row['export_downloads']),
            'erasurePending' => RightsRequestKind::Erasure === $kind && RightsRequestStatus::Decided === $status && $decision?->isGrant() && null === $row['executed_at'],
            'erasureExecutionEnabled' => $this->settings->erasureExecutionEnabled(),
        ];
    }

    /** @return list<array{id: string, municipality: string, reference: string, kind: RightsRequestKind, status: RightsRequestStatus, receivedAt: \DateTimeImmutable, dueAt: \DateTimeImmutable, overdue: bool, decision: ?RightsDecision, dossiers: int}> */
    private function rows(?Uuid $municipalityId, ?string $status): array
    {
        $now = RightsRequestStore::utc($this->clock->now());
        $utc = new \DateTimeZone('UTC');
        $out = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, municipality_id, public_reference, kind, status, received_at, due_at, extended_until, decision, dossier_count FROM rights_request WHERE 1 = 1'
            .(null === $municipalityId ? '' : ' AND municipality_id = :m').' ORDER BY (status = \'CLOSED\'), COALESCE(extended_until, due_at), id LIMIT 500',
            null === $municipalityId ? [] : ['m' => $municipalityId->toBinary()],
        ) as $row) {
            $due = new \DateTimeImmutable((string) ($row['extended_until'] ?? $row['due_at']), $utc);
            $closed = 'CLOSED' === $row['status'];
            $out[] = [
                'id' => Uuid::fromBinary((string) $row['id'])->toRfc4122(),
                'municipality' => Uuid::fromBinary((string) $row['municipality_id'])->toRfc4122(),
                'reference' => (string) $row['public_reference'],
                'kind' => RightsRequestKind::from((string) $row['kind']),
                'status' => RightsRequestStatus::from((string) $row['status']),
                'receivedAt' => new \DateTimeImmutable((string) $row['received_at'], $utc),
                'dueAt' => $due,
                'overdue' => !$closed && $due < $now,
                'decision' => null === $row['decision'] ? null : RightsDecision::from((string) $row['decision']),
                'dossiers' => (int) $row['dossier_count'],
            ];
        }

        return $out;
    }
}
