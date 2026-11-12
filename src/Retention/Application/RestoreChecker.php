<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Event\Domain\ClosureStatistics;
use App\Retention\Domain\RestoreItem;
use App\Retention\Domain\RestoreReport;
use App\Shared\Application\FileStorageInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle de cohérence APRÈS restauration ou en routine (LOT 05.6.7) : LECTURE SEULE. Confronte le registre externe (chaîne et points d'ancrage), les enregistrements SQL et l'état
 * réel. Il QUALIFIE chaque écart mais ne répare rien : un écart n'est jamais effacé pour masquer une incohérence, et seuls les éléments REJOUABLES sont proposés au rejeu.
 *
 * Preuve externe : le rejeu exige un registre présent et chaîné, des points d'ancrage présents et concordants (et, si fournie, une copie externe concordante).
 */
final class RestoreChecker
{
    public function __construct(
        private readonly PurgeRegisterFile $register,
        private readonly RegisterAnchors $anchors,
        private readonly Connection $connection,
        private readonly FileStorageInterface $storage,
    ) {
    }

    public function check(?string $externalAnchors = null): RestoreReport
    {
        $chain = $this->register->verify();
        $anchorReport = $this->anchors->verify($chain);
        $proof = [];
        $items = [];
        if (!$chain->fileExists) {
            $proof[] = 'REGISTER_ABSENT';
        }
        foreach ($chain->breaks as $break) {
            $proof[] = $break['code'].'@'.$break['line'];
        }
        foreach ($anchorReport->problems as $problem) {
            $proof[] = $problem;
        }
        if (null !== $externalAnchors) {
            foreach ($this->anchors->verify($chain, $externalAnchors)->problems as $problem) {
                $proof[] = 'EXTERNAL:'.$problem;
            }
        }
        // Système vierge (aucun registre, aucun point d'ancrage, aucune ligne SQL) : rien à prouver, aucune anomalie.
        if (!$chain->fileExists && 0 === $anchorReport->anchors && 0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM retention_purge_record') && (null === $externalAnchors)) {
            $proof = [];
        }
        foreach ($proof as $problem) {
            $items[] = new RestoreItem(RestoreItem::PROOF_PROBLEM, RestoreItem::SEVERITY_BLOCKING, RestoreItem::INCIDENT, detail: $problem);
        }

        $lines = array_values(array_filter($this->register->lines(), static fn (?array $l): bool => null !== $l && \is_string($l['id'] ?? null) && \is_string($l['kind'] ?? null)));
        $sql = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id FROM retention_purge_record') as $row) {
            $sql[Uuid::fromBinary((string) $row['id'])->toRfc4122()] = true;
        }
        $fileIds = [];
        foreach ($lines as $line) {
            $fileIds[(string) $line['id']] = true;
        }
        foreach (array_keys(array_diff_key($sql, $fileIds)) as $id) {
            $items[] = new RestoreItem(RestoreItem::SQL_ONLY, RestoreItem::SEVERITY_WARNING, RestoreItem::REPAIRABLE, recordId: $id);
        }

        $purgedDossiers = [];
        $reintroduced = [];
        $dossierItems = [];
        $sharedLines = [];
        foreach ($lines as $line) {
            $id = (string) $line['id'];
            $kind = (string) $line['kind'];
            $inSql = isset($sql[$id]);
            $common = ['kind' => $kind, 'recordId' => $id, 'eventReference' => (string) ($line['eventReference'] ?? ''), 'municipalityId' => (string) ($line['municipality'] ?? '')];
            if ('DOSSIER' === $kind && \is_string($line['dossier'] ?? null)) {
                $purgedDossiers[(string) $line['event']][(string) $line['dossier']] = true;
                $present = $this->dossierRow((string) $line['dossier']);
                if (false === $present) {
                    if (!$inSql) {
                        $items[] = new RestoreItem(RestoreItem::FILE_ONLY, RestoreItem::SEVERITY_WARNING, RestoreItem::ADOPTABLE, ...$common, dossierId: (string) $line['dossier'], requestId: (string) ($line['request'] ?? ''));
                    }
                    continue;
                }
                if ($inSql) {
                    $items[] = new RestoreItem(RestoreItem::PURGED_BUT_PRESENT, RestoreItem::SEVERITY_BLOCKING, RestoreItem::INCIDENT, ...$common, dossierId: (string) $line['dossier'], requestId: (string) ($line['request'] ?? ''));
                    continue;
                }
                $reintroduced[(string) $line['dossier']] = true;
                $item = new RestoreItem(RestoreItem::REINTRODUCED_DOSSIER, RestoreItem::SEVERITY_BLOCKING, $this->qualifyDossier($line, $present), ...$common, dossierId: (string) $line['dossier'], requestId: (string) ($line['request'] ?? ''));
                $dossierItems[] = $item;
                $items[] = $item;
            } elseif ('SHARED' === $kind && \is_string($line['request'] ?? null)) {
                $sharedLines[] = [$line, $common, $inSql];
            } elseif ('EVENT_DELETED' === $kind) {
                $exists = false !== $this->connection->fetchOne('SELECT 1 FROM event_event WHERE id = :e', ['e' => Uuid::fromString((string) $line['event'])->toBinary()]);
                if ($exists) {
                    $items[] = new RestoreItem(RestoreItem::REINTRODUCED_EVENT, RestoreItem::SEVERITY_BLOCKING, RestoreItem::MANUAL, ...$common, detail: 'À refaire par l\'administrateur de l\'organisatrice dans l\'écran (acte humain).');
                } elseif (!$inSql) {
                    $items[] = new RestoreItem(RestoreItem::FILE_ONLY, RestoreItem::SEVERITY_WARNING, RestoreItem::ADOPTABLE, ...$common);
                }
            } elseif ('NOTICE' === $kind && !$inSql) {
                $items[] = new RestoreItem(RestoreItem::FILE_ONLY, RestoreItem::SEVERITY_WARNING, RestoreItem::ADOPTABLE, ...$common);
            }
        }

        $replayableDossiers = [];
        foreach ($dossierItems as $item) {
            if (RestoreItem::REPLAYABLE === $item->qualification) {
                $replayableDossiers[$item->dossierId] = true;
            }
        }
        $reintroducedRequests = [];
        foreach ($dossierItems as $item) {
            $reintroducedRequests[$item->requestId] = true;
        }
        foreach ($sharedLines as [$line, $common, $inSql]) {
            $requestId = Uuid::fromString((string) $line['request']);
            if (false === $this->connection->fetchOne('SELECT 1 FROM request_permit_request WHERE id = :r', ['r' => $requestId->toBinary()])) {
                if (!$inSql) {
                    $items[] = new RestoreItem(RestoreItem::FILE_ONLY, RestoreItem::SEVERITY_WARNING, RestoreItem::ADOPTABLE, ...$common, requestId: (string) $line['request']);
                }
                continue;
            }
            $reintroducedRequests[(string) $line['request']] = true;
            if ($inSql) {
                $items[] = new RestoreItem(RestoreItem::PURGED_BUT_PRESENT, RestoreItem::SEVERITY_BLOCKING, RestoreItem::INCIDENT, ...$common, requestId: (string) $line['request']);
                continue;
            }
            $remaining = array_map(static fn (mixed $v): string => Uuid::fromBinary((string) $v)->toRfc4122(), $this->connection->fetchFirstColumn('SELECT id FROM request_permit_request_municipality WHERE request_id = :r', ['r' => $requestId->toBinary()]));
            $blocked = array_diff($remaining, array_keys($replayableDossiers));
            $items[] = new RestoreItem(RestoreItem::REINTRODUCED_SHARED, RestoreItem::SEVERITY_BLOCKING, [] === $blocked ? RestoreItem::REPLAYABLE : RestoreItem::BLOCKED_DOSSIERS_REMAIN, ...$common, requestId: (string) $line['request']);
        }

        $items = [...$items, ...$this->missingReferences($lines), ...$this->losses($lines, $anchorReport->last), ...$this->files($reintroducedRequests), ...$this->counts($purgedDossiers, $reintroduced)];
        usort($items, static fn (RestoreItem $a, RestoreItem $b): int => [$a->code, $a->recordId, $a->dossierId, $a->requestId, $a->detail] <=> [$b->code, $b->recordId, $b->dossierId, $b->requestId, $b->detail]);
        $hash = hash('sha256', json_encode([array_map(static fn (RestoreItem $i): array => $i->toArray(), $items), $chain->headHash, $chain->lastSeq, $anchorReport->anchors, $anchorReport->last['head'] ?? null], \JSON_THROW_ON_ERROR));

        return new RestoreReport($items, $proof, $hash, $chain->lines, $anchorReport->anchors);
    }

    /** Dossier d'origine du rejeu : la demande de droits qui a ordonné l'effacement, si elle existe encore (sinon aucune exemption). */
    public function originRightsRequest(?string $batch): ?Uuid
    {
        if (null === $batch || !Uuid::isValid($batch)) {
            return null;
        }
        $id = Uuid::fromString($batch);

        return false === $this->connection->fetchOne('SELECT 1 FROM rights_request WHERE id = :i', ['i' => $id->toBinary()]) ? null : $id;
    }

    /** @return array<string, mixed>|false */
    private function dossierRow(string $dossierId): array|false
    {
        if (!Uuid::isValid($dossierId)) {
            return false;
        }

        return $this->connection->fetchAssociative(
            'SELECT rm.id, rm.municipality_id, rm.request_id, e.status FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id WHERE rm.id = :d',
            ['d' => Uuid::fromString($dossierId)->toBinary()],
        );
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $row
     */
    private function qualifyDossier(array $line, array $row): string
    {
        $dossier = Uuid::fromString((string) $line['dossier'])->toBinary();
        if (Uuid::fromBinary((string) $row['municipality_id'])->toRfc4122() !== ($line['municipality'] ?? null) || Uuid::fromBinary((string) $row['request_id'])->toRfc4122() !== ($line['request'] ?? null)) {
            return RestoreItem::BLOCKED_MISMATCH;
        }
        if ('CLOSED' !== $row['status']) {
            return RestoreItem::BLOCKED_EVENT_NOT_CLOSED; // la restauration a pu rouvrir l'événement : refaire la clôture par le circuit normal
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM archive_legal_hold WHERE request_municipality_id = :d AND active_marker = 1', ['d' => $dossier]) > 0) {
            return RestoreItem::BLOCKED_HOLD; // la levée du gel a pu être perdue : décision humaine
        }
        $origin = $this->originRightsRequest(\is_string($line['batch'] ?? null) ? $line['batch'] : null);
        $open = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM rights_request_dossier l JOIN rights_request q ON q.id = l.rights_request_id WHERE l.request_municipality_id = :d AND q.status <> \'CLOSED\''.(null === $origin ? '' : ' AND q.id <> :own'),
            ['d' => $dossier] + (null === $origin ? [] : ['own' => $origin->toBinary()]),
        );

        return $open > 0 ? RestoreItem::BLOCKED_RIGHTS_OPEN : RestoreItem::REPLAYABLE;
    }

    /**
     * Événements et communes cités par le registre mais absents de la base : ils existaient lors des purges (données postérieures à la sauvegarde perdues).
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return list<RestoreItem>
     */
    private function missingReferences(array $lines): array
    {
        $deleted = [];
        $events = [];
        $municipalities = [];
        foreach ($lines as $line) {
            if ('EVENT_DELETED' === $line['kind'] && \is_string($line['event'] ?? null)) {
                $deleted[$line['event']] = true;
            }
            if (\is_string($line['event'] ?? null)) {
                $events[$line['event']] = (string) ($line['eventReference'] ?? '');
            }
            if (\is_string($line['municipality'] ?? null)) {
                $municipalities[$line['municipality']] = true;
            }
        }
        $items = [];
        foreach ($events as $id => $reference) {
            if (!isset($deleted[$id]) && Uuid::isValid($id) && false === $this->connection->fetchOne('SELECT 1 FROM event_event WHERE id = :e', ['e' => Uuid::fromString($id)->toBinary()])) {
                $items[] = new RestoreItem(RestoreItem::EVENT_MISSING, RestoreItem::SEVERITY_BLOCKING, RestoreItem::ACKNOWLEDGE_REQUIRED, eventReference: $reference, detail: $id);
            }
        }
        foreach (array_keys($municipalities) as $id) {
            if (Uuid::isValid($id) && false === $this->connection->fetchOne('SELECT 1 FROM municipality WHERE id = :m', ['m' => Uuid::fromString($id)->toBinary()])) {
                $items[] = new RestoreItem(RestoreItem::MUNICIPALITY_MISSING, RestoreItem::SEVERITY_BLOCKING, RestoreItem::ACKNOWLEDGE_REQUIRED, municipalityId: $id);
            }
        }

        return $items;
    }

    /**
     * Pertes POSSIBLES postérieures à la sauvegarde : repères monotones en régression par rapport au dernier point d'ancrage, opérations du registre plus récentes que le dernier audit.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null  $last
     *
     * @return list<RestoreItem>
     */
    private function losses(array $lines, ?array $last): array
    {
        $now = $this->anchors->markers();
        $details = [];
        if (null !== $last) {
            if (\is_string($last['auditLast'] ?? null) && (!\is_string($now['auditLast']) || $now['auditLast'] < $last['auditLast'])) {
                $details[] = sprintf('dernier audit en base %s, repère %s', \is_string($now['auditLast']) ? $now['auditLast'] : 'aucun', $last['auditLast']);
            }
            foreach (['users' => 'comptes', 'municipalities' => 'communes', 'rightsRequests' => 'demandes de droits'] as $key => $label) {
                if (isset($last[$key]) && $now[$key] < $last[$key]) {
                    $details[] = sprintf('%s : %d en base, %d au repère', $label, $now[$key], $last[$key]);
                }
            }
        }
        if (\is_string($now['auditLast'] ?? null)) {
            foreach ($lines as $line) {
                if (\is_string($line['occurredAt'] ?? null) && $line['occurredAt'] > $now['auditLast']) {
                    $details[] = sprintf('opération du registre du %s postérieure au dernier audit en base (%s)', $line['occurredAt'], $now['auditLast']);
                    break;
                }
            }
        } elseif ([] !== $lines) {
            $details[] = 'registre non vide mais aucun audit en base';
        }

        return array_map(static fn (string $d): RestoreItem => new RestoreItem(RestoreItem::POSSIBLE_DATA_LOSS, RestoreItem::SEVERITY_BLOCKING, RestoreItem::ACKNOWLEDGE_REQUIRED, detail: $d), $details);
    }

    /**
     * @param array<string, true> $reintroducedRequests demandes dont les fichiers ont été supprimés par une purge (absence ATTENDUE)
     *
     * @return list<RestoreItem>
     */
    private function files(array $reintroducedRequests): array
    {
        $missing = [];
        foreach ($this->connection->fetchAllAssociative('SELECT request_id, storage_key FROM request_permit_request_attachment ORDER BY request_id, id') as $row) {
            if (!$this->storage->exists((string) $row['storage_key'])) {
                $request = Uuid::fromBinary((string) $row['request_id'])->toRfc4122();
                $missing[$request] = ($missing[$request] ?? 0) + 1;
            }
        }
        $items = [];
        foreach ($missing as $request => $count) {
            $expected = isset($reintroducedRequests[$request]);
            $items[] = new RestoreItem($expected ? RestoreItem::FILES_EXPECTED_MISSING : RestoreItem::FILES_MISSING, $expected ? RestoreItem::SEVERITY_INFO : RestoreItem::SEVERITY_BLOCKING, $expected ? RestoreItem::EXPECTED : RestoreItem::INCIDENT, requestId: $request, detail: $count.' fichier(s)');
        }

        return $items;
    }

    /**
     * Pour un événement clos aux compteurs exacts : dossiers présents (hors réintroduits) + dossiers purgés enregistrés = dossiers à la clôture. Sinon, suppression non enregistrée possible.
     *
     * @param array<string, array<string, true>> $purged       dossiers purgés par événement
     * @param array<string, true>                $reintroduced dossiers réintroduits
     *
     * @return list<RestoreItem>
     */
    private function counts(array $purged, array $reintroduced): array
    {
        $items = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, public_reference, closure_statistics FROM event_event WHERE status = \'CLOSED\' AND closure_statistics IS NOT NULL') as $event) {
            $data = json_decode((string) $event['closure_statistics'], true);
            if (!\is_array($data) || ClosureStatistics::VERSION !== ($data['v'] ?? null)) {
                continue; // compteurs exacts déjà remplacés par le résumé publié
            }
            $id = Uuid::fromBinary((string) $event['id']);
            $present = 0;
            foreach ($this->connection->fetchFirstColumn('SELECT rm.id FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id WHERE r.event_id = :e', ['e' => $id->toBinary()]) as $binary) {
                if (!isset($reintroduced[Uuid::fromBinary((string) $binary)->toRfc4122()])) {
                    ++$present;
                }
            }
            $expected = ClosureStatistics::fromArray($data)->dossiers();
            $recorded = \count($purged[$id->toRfc4122()] ?? []);
            if ($present + $recorded !== $expected) {
                $items[] = new RestoreItem(RestoreItem::COUNT_MISMATCH, RestoreItem::SEVERITY_BLOCKING, RestoreItem::INCIDENT, eventReference: (string) $event['public_reference'], detail: sprintf('à la clôture %d, présents %d, purgés enregistrés %d', $expected, $present, $recorded));
            }
        }

        return $items;
    }
}
