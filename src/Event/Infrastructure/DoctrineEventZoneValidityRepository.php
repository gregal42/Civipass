<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\EventZoneValidityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventZoneValidityRepository::class)]
final readonly class DoctrineEventZoneValidityRepository implements EventZoneValidityRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(EventZoneValidity $validity): void
    {
        $this->entityManager->persist($validity);
    }

    public function find(Uuid $id): ?EventZoneValidity
    {
        return $this->entityManager->find(EventZoneValidity::class, $id);
    }

    public function findActiveByZone(Uuid $zoneId): array
    {
        /* @var list<EventZoneValidity> */
        return $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(EventZoneValidity::class, 'v')
            ->where('IDENTITY(v.zone) = :zone')
            ->andWhere('v.deletedAt IS NULL')
            ->setParameter('zone', $zoneId, UuidType::NAME)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByZones(array $zoneIds): array
    {
        if ([] === $zoneIds) {
            return [];
        }

        /* @var list<EventZoneValidity> */
        return $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(EventZoneValidity::class, 'v')
            ->where('IDENTITY(v.zone) IN (:zones)')
            ->andWhere('v.deletedAt IS NULL')
            ->setParameter('zones', array_map(static fn (Uuid $id): string => $id->toBinary(), $zoneIds), ArrayParameterType::STRING)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findHistoryByZone(Uuid $zoneId): array
    {
        /* @var list<EventZoneValidity> */
        return $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(EventZoneValidity::class, 'v')
            ->where('IDENTITY(v.zone) = :zone')
            ->setParameter('zone', $zoneId, UuidType::NAME)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
