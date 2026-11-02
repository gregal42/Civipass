<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(AuditLogRepository::class)]
final readonly class DoctrineAuditLogRepository implements AuditLogRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByMunicipality(Uuid $municipalityId, int $limit = 100): array
    {
        return array_values($this->entityManager->createQueryBuilder()
            ->select('a')->from(AuditLog::class, 'a')
            ->where('a.municipalityId = :municipalityId')
            ->setParameter('municipalityId', $municipalityId, 'uuid')
            ->orderBy('a.occurredAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult());
    }

    public function findAll(int $limit = 100): array
    {
        return array_values($this->entityManager->createQueryBuilder()
            ->select('a')->from(AuditLog::class, 'a')
            ->orderBy('a.occurredAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult());
    }
}
