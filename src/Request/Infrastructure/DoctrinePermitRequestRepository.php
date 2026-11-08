<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Domain\PartLocator;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\RequestIdempotencyKey;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PermitRequestRepository::class)]
final readonly class DoctrinePermitRequestRepository implements PermitRequestRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PermitRequest $request, RequestIdempotencyKey $key): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->persist($key);
    }

    public function findByIdempotencyKey(Uuid $eventId, string $keyHash): ?PermitRequest
    {
        $request = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PermitRequest::class, 'r')
            ->join(RequestIdempotencyKey::class, 'k', 'WITH', 'k.request = r')
            ->where('IDENTITY(k.event) = :event')
            ->andWhere('k.keyHash = :hash')
            ->setParameter('event', $eventId, 'uuid')
            ->setParameter('hash', $keyHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $request instanceof PermitRequest ? $request : null;
    }

    public function locatePart(Uuid $requestId, Uuid $municipalityId): ?PartLocator
    {
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT m.id AS part_id, m.participation_id, p.event_id FROM request_permit_request_municipality m'
            .' JOIN event_participation p ON p.id = m.participation_id WHERE m.request_id = :request AND m.municipality_id = :municipality',
            ['request' => $requestId->toBinary(), 'municipality' => $municipalityId->toBinary()],
        );
        if (false === $row) {
            return null;
        }
        $zoneIds = $connection->fetchFirstColumn('SELECT zone_id FROM request_permit_request_zone WHERE request_municipality_id = :part ORDER BY zone_id', ['part' => $row['part_id']]);

        return new PartLocator(
            Uuid::fromBinary($row['part_id']),
            Uuid::fromBinary($row['participation_id']),
            Uuid::fromBinary($row['event_id']),
            array_map(static fn (string $id): Uuid => Uuid::fromBinary($id), $zoneIds),
        );
    }

    public function lockPart(Uuid $partId, Uuid $municipalityId): ?PermitRequestMunicipality
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(PermitRequestMunicipality::class, 'm')
            ->where('m.id = :id')
            ->andWhere('IDENTITY(m.municipality) = :municipality')
            ->setParameter('id', $partId, 'uuid')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true); // une part déjà gérée est relue sous verrou, pas depuis l'instantané

        $part = $query->getOneOrNullResult();

        return $part instanceof PermitRequestMunicipality ? $part : null;
    }

    public function findPart(Uuid $partId, Uuid $municipalityId): ?PermitRequestMunicipality
    {
        $part = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(PermitRequestMunicipality::class, 'm')
            ->where('m.id = :id')
            ->andWhere('IDENTITY(m.municipality) = :municipality')
            ->setParameter('id', $partId, 'uuid')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $part instanceof PermitRequestMunicipality ? $part : null;
    }
}
