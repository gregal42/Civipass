<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Application\ClosedEventResolver;
use App\Event\Application\EventStatusProbe;
use App\Event\Domain\EventStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ClosedEventResolver::class)]
#[AsAlias(EventStatusProbe::class)]
final readonly class DbalClosedEventResolver implements ClosedEventResolver, EventStatusProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function eventStatusOfDossier(Uuid $municipalityId, Uuid $requestId): ?EventStatus
    {
        $status = $this->connection->fetchOne(
            'SELECT e.status FROM request_permit_request_municipality rm'
            .' JOIN request_permit_request r ON r.id = rm.request_id'
            .' JOIN event_event e ON e.id = r.event_id'
            .' WHERE rm.municipality_id = :municipality AND rm.request_id = :request',
            ['municipality' => $municipalityId->toBinary(), 'request' => $requestId->toBinary()],
        );

        return \is_string($status) ? EventStatus::tryFrom($status) : null;
    }

    public function isClosed(Uuid $eventId): bool
    {
        return EventStatus::Closed->value === $this->connection->fetchOne('SELECT status FROM event_event WHERE id = :id', ['id' => $eventId->toBinary()]);
    }

    public function isClosedByPermit(Uuid $permitId): bool
    {
        return EventStatus::Closed->value === $this->connection->fetchOne(
            'SELECT e.status FROM permit_permit p JOIN event_event e ON e.id = p.event_id WHERE p.id = :id',
            ['id' => $permitId->toBinary()],
        );
    }
}
