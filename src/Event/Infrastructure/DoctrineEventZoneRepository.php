<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\ZoneStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventZoneRepository::class)]
final readonly class DoctrineEventZoneRepository implements EventZoneRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(EventZone $zone): void
    {
        $this->entityManager->persist($zone);
    }

    public function find(Uuid $id): ?EventZone
    {
        return $this->entityManager->find(EventZone::class, $id);
    }

    public function findForMunicipality(Uuid $zoneId, Uuid $municipalityId): ?EventZone
    {
        return $this->entityManager->createQueryBuilder()
            ->select('z')
            ->from(EventZone::class, 'z')
            ->join('z.participation', 'p')
            ->where('z.id = :zone')
            ->andWhere('IDENTITY(p.municipality) = :municipality')
            ->setParameter('zone', $zoneId, UuidType::NAME)
            ->setParameter('municipality', $municipalityId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByParticipation(Uuid $participationId): array
    {
        /* @var list<EventZone> */
        return $this->entityManager->createQueryBuilder()
            ->select('z')
            ->from(EventZone::class, 'z')
            ->where('IDENTITY(z.participation) = :participation')
            ->andWhere('z.deletedAt IS NULL')
            ->setParameter('participation', $participationId, UuidType::NAME)
            ->orderBy('z.createdAt', 'ASC')
            ->addOrderBy('z.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdsInParticipation(array $zoneIds, Uuid $participationId): array
    {
        // Les UUID binaires sont liés comme chaînes, exactement comme le fait UuidType : un binding BINARY ne
        // correspondrait pas aux valeurs stockées sur certains moteurs.
        if ([] === $zoneIds) {
            return [];
        }

        /* @var list<EventZone> */
        return $this->entityManager->createQueryBuilder()
            ->select('z')
            ->from(EventZone::class, 'z')
            ->where('z.id IN (:ids)')
            ->andWhere('IDENTITY(z.participation) = :participation')
            ->andWhere('z.deletedAt IS NULL')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toBinary(), $zoneIds), ArrayParameterType::STRING)
            ->setParameter('participation', $participationId, UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function existsActivationReadyZone(Uuid $eventId): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(z.id)')
            ->from(EventZone::class, 'z')
            ->join('z.participation', 'p')
            ->where('IDENTITY(p.event) = :event')
            ->andWhere('p.status = :participationActive')
            ->andWhere('z.status = :zoneActive')
            ->andWhere('z.confirmedAt IS NOT NULL')
            ->andWhere('z.deletedAt IS NULL')
            ->andWhere('z.validityMode = :duration OR (z.validityMode = :specific AND EXISTS ('
                .'SELECT 1 FROM '.EventZoneValidity::class.' v WHERE v.zone = z AND v.deletedAt IS NULL))')
            ->setParameter('event', $eventId, UuidType::NAME)
            ->setParameter('participationActive', ParticipationStatus::Active->value)
            ->setParameter('zoneActive', ZoneStatus::Active->value)
            ->setParameter('duration', ValidityMode::EventDuration->value)
            ->setParameter('specific', ValidityMode::SpecificPeriods->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
