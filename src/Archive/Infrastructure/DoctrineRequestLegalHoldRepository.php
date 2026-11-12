<?php

declare(strict_types=1);

namespace App\Archive\Infrastructure;

use App\Archive\Domain\RequestLegalHold;
use App\Archive\Domain\RequestLegalHoldRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RequestLegalHoldRepository::class)]
final readonly class DoctrineRequestLegalHoldRepository implements RequestLegalHoldRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(RequestLegalHold $hold): void
    {
        $this->em->persist($hold);
    }

    public function find(Uuid $id): ?RequestLegalHold
    {
        return $this->em->find(RequestLegalHold::class, $id);
    }

    public function findActive(Uuid $dossierId, bool $lock = false): ?RequestLegalHold
    {
        $query = $this->em->createQueryBuilder()->select('h')->from(RequestLegalHold::class, 'h')
            ->where('IDENTITY(h.dossier) = :d AND h.activeMarker = 1')->setParameter('d', $dossierId, 'uuid')->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }
        $found = $query->getOneOrNullResult();

        return $found instanceof RequestLegalHold ? $found : null;
    }

    public function history(Uuid $dossierId): array
    {
        /** @var list<RequestLegalHold> $rows */
        $rows = $this->em->createQueryBuilder()->select('h')->from(RequestLegalHold::class, 'h')
            ->where('IDENTITY(h.dossier) = :d')->orderBy('h.placedAt', 'DESC')->addOrderBy('h.id', 'DESC')
            ->setParameter('d', $dossierId, 'uuid')->getQuery()->getResult();

        return $rows;
    }

    public function activeAmong(array $dossierIds): array
    {
        if ([] === $dossierIds) {
            return [];
        }
        /** @var list<RequestLegalHold> $rows */
        $rows = $this->em->createQueryBuilder()->select('h')->from(RequestLegalHold::class, 'h')
            ->where('IDENTITY(h.dossier) IN (:ids) AND h.activeMarker = 1')->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toBinary(), $dossierIds), \Doctrine\DBAL\ArrayParameterType::BINARY)
            ->getQuery()->getResult();
        $byDossier = [];
        foreach ($rows as $row) {
            $byDossier[$row->dossier()->id()->toRfc4122()] = $row;
        }

        return $byDossier;
    }

    public function overdue(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<RequestLegalHold> $rows */
        $rows = $this->em->createQueryBuilder()->select('h')->from(RequestLegalHold::class, 'h')
            ->where('h.activeMarker = 1 AND h.reviewDueAt <= :now')->orderBy('h.reviewDueAt', 'ASC')->setMaxResults($limit)
            ->setParameter('now', $now)->getQuery()->getResult();

        return $rows;
    }
}
