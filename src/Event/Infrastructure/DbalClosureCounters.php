<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Application\ClosureCounters;
use App\Event\Domain\ClosureStatistics;
use App\Event\Domain\MunicipalityCounters;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Agrégats SQL ensemblistes (jamais de lecture par entité) : dossiers communaux par commune et statut, autorisations par commune, demandes distinctes.
 * Aucune colonne personnelle n'est lue. Communes comptées : celles qui ont au moins un dossier et celles qui ont réellement participé (`activated_at`).
 */
#[AsAlias(ClosureCounters::class)]
final readonly class DbalClosureCounters implements ClosureCounters
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(Uuid $eventId): ClosureStatistics
    {
        $event = ['event' => $eventId->toBinary()];
        $rows = [];
        $row = static function (array &$rows, string $binary): string {
            $id = Uuid::fromBinary($binary)->toRfc4122();
            $rows[$id] ??= ['approved' => 0, 'refused' => 0, 'pending' => 0, 'authorizations' => 0];

            return $id;
        };
        foreach ($this->connection->fetchAllAssociative('SELECT municipality_id FROM event_participation WHERE event_id = :event AND activated_at IS NOT NULL', $event) as $p) {
            $row($rows, (string) $p['municipality_id']);
        }
        $statuses = ['APPROVED' => 'approved', 'REFUSED' => 'refused', 'PENDING' => 'pending'];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT rm.municipality_id, rm.status, COUNT(*) AS n FROM request_permit_request_municipality rm'
            .' JOIN request_permit_request r ON r.id = rm.request_id WHERE r.event_id = :event GROUP BY rm.municipality_id, rm.status',
            $event,
        ) as $d) {
            $id = $row($rows, (string) $d['municipality_id']);
            $rows[$id][$statuses[(string) $d['status']] ?? throw new \LogicException('Statut de dossier inconnu.')] = (int) $d['n'];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT a.municipality_id, COUNT(*) AS n FROM permit_authorization a JOIN permit_permit p ON p.id = a.permit_id WHERE p.event_id = :event GROUP BY a.municipality_id',
            $event,
        ) as $a) {
            $rows[$row($rows, (string) $a['municipality_id'])]['authorizations'] = (int) $a['n'];
        }
        $requests = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request_permit_request WHERE event_id = :event', $event);
        ksort($rows);

        return new ClosureStatistics($requests, array_map(
            static fn (string $id, array $c): MunicipalityCounters => new MunicipalityCounters($id, $c['approved'], $c['refused'], $c['pending'], $c['authorizations']),
            array_keys($rows),
            $rows,
        ));
    }
}
