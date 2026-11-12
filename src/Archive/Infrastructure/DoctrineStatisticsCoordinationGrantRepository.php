<?php

declare(strict_types=1);

namespace App\Archive\Infrastructure;

use App\Archive\Domain\StatisticsCoordinationGrant;
use App\Archive\Domain\StatisticsCoordinationGrantRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(StatisticsCoordinationGrantRepository::class)]
final readonly class DoctrineStatisticsCoordinationGrantRepository implements StatisticsCoordinationGrantRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(StatisticsCoordinationGrant $grant): void
    {
        $this->em->persist($grant);
    }

    public function find(Uuid $id): ?StatisticsCoordinationGrant
    {
        return $this->em->find(StatisticsCoordinationGrant::class, $id);
    }

    public function findActive(Uuid $eventId, Uuid $userId, bool $lock = false): ?StatisticsCoordinationGrant
    {
        $query = $this->em->createQueryBuilder()->select('g')->from(StatisticsCoordinationGrant::class, 'g')
            ->where('IDENTITY(g.event) = :e AND IDENTITY(g.user) = :u AND g.activeMarker = 1')
            ->setParameter('e', $eventId, 'uuid')->setParameter('u', $userId, 'uuid')->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }
        $found = $query->getOneOrNullResult();

        return $found instanceof StatisticsCoordinationGrant ? $found : null;
    }

    public function activeForUserInOrganizer(Uuid $userId, Uuid $municipalityId): array
    {
        /** @var list<StatisticsCoordinationGrant> $rows */
        $rows = $this->em->createQueryBuilder()->select('g')->from(StatisticsCoordinationGrant::class, 'g')->join('g.event', 'e')
            ->where('IDENTITY(g.user) = :u AND IDENTITY(e.organizerMunicipality) = :m AND g.activeMarker = 1')
            ->setParameter('u', $userId, 'uuid')->setParameter('m', $municipalityId, 'uuid')->getQuery()->getResult();

        return $rows;
    }

    public function activeForUser(Uuid $userId): array
    {
        /** @var list<StatisticsCoordinationGrant> $rows */
        $rows = $this->em->createQueryBuilder()->select('g')->from(StatisticsCoordinationGrant::class, 'g')
            ->where('IDENTITY(g.user) = :u AND g.activeMarker = 1')->setParameter('u', $userId, 'uuid')->getQuery()->getResult();

        return $rows;
    }

    public function forEvent(Uuid $eventId): array
    {
        /** @var list<StatisticsCoordinationGrant> $rows */
        $rows = $this->em->createQueryBuilder()->select('g')->from(StatisticsCoordinationGrant::class, 'g')
            ->where('IDENTITY(g.event) = :e')->orderBy('g.grantedAt', 'DESC')->addOrderBy('g.id', 'DESC')
            ->setParameter('e', $eventId, 'uuid')->getQuery()->getResult();

        return $rows;
    }
}
