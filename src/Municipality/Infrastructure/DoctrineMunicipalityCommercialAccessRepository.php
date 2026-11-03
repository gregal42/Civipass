<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityCommercialAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(MunicipalityCommercialAccessRepository::class)]
final readonly class DoctrineMunicipalityCommercialAccessRepository implements MunicipalityCommercialAccessRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(MunicipalityCommercialAccess $access): void
    {
        $this->entityManager->persist($access);
    }

    public function find(Uuid $id): ?MunicipalityCommercialAccess
    {
        return $this->entityManager->find(MunicipalityCommercialAccess::class, $id);
    }

    public function findByMunicipality(Uuid $municipalityId): array
    {
        /* @var list<MunicipalityCommercialAccess> */
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(MunicipalityCommercialAccess::class, 'a')
            ->where('IDENTITY(a.municipality) = :municipality')
            ->setParameter('municipality', $municipalityId, UuidType::NAME)
            ->orderBy('a.grantedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findValidAt(Uuid $municipalityId, \DateTimeImmutable $at): array
    {
        /* @var list<MunicipalityCommercialAccess> */
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(MunicipalityCommercialAccess::class, 'a')
            ->where('IDENTITY(a.municipality) = :municipality')
            ->andWhere('a.grantedAt <= :at')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :at')
            ->andWhere('a.revokedAt IS NULL OR a.revokedAt > :at')
            ->setParameter('municipality', $municipalityId, UuidType::NAME)
            ->setParameter('at', $at, 'datetime_immutable')
            ->orderBy('a.grantedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
