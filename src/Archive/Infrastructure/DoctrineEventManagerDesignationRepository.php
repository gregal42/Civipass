<?php

declare(strict_types=1);

namespace App\Archive\Infrastructure;

use App\Archive\Domain\EventManagerDesignation;
use App\Archive\Domain\EventManagerDesignationRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventManagerDesignationRepository::class)]
final readonly class DoctrineEventManagerDesignationRepository implements EventManagerDesignationRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(EventManagerDesignation $designation): void
    {
        $this->em->persist($designation);
    }

    public function find(Uuid $id): ?EventManagerDesignation
    {
        return $this->em->find(EventManagerDesignation::class, $id);
    }

    public function findActive(Uuid $participationId, Uuid $userId, bool $lock = false): ?EventManagerDesignation
    {
        $query = $this->em->createQueryBuilder()->select('d')->from(EventManagerDesignation::class, 'd')
            ->where('IDENTITY(d.participation) = :p AND IDENTITY(d.user) = :u AND d.activeMarker = 1')
            ->setParameter('p', $participationId, 'uuid')->setParameter('u', $userId, 'uuid')->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }
        $found = $query->getOneOrNullResult();

        return $found instanceof EventManagerDesignation ? $found : null;
    }

    public function activeForUserInMunicipality(Uuid $userId, Uuid $municipalityId): array
    {
        /** @var list<EventManagerDesignation> $rows */
        $rows = $this->em->createQueryBuilder()->select('d')->from(EventManagerDesignation::class, 'd')->join('d.participation', 'p')
            ->where('IDENTITY(d.user) = :u AND IDENTITY(p.municipality) = :m AND d.activeMarker = 1')
            ->setParameter('u', $userId, 'uuid')->setParameter('m', $municipalityId, 'uuid')->getQuery()->getResult();

        return $rows;
    }

    public function activeForUser(Uuid $userId): array
    {
        /** @var list<EventManagerDesignation> $rows */
        $rows = $this->em->createQueryBuilder()->select('d')->from(EventManagerDesignation::class, 'd')
            ->where('IDENTITY(d.user) = :u AND d.activeMarker = 1')->setParameter('u', $userId, 'uuid')->getQuery()->getResult();

        return $rows;
    }

    public function forParticipation(Uuid $participationId): array
    {
        /** @var list<EventManagerDesignation> $rows */
        $rows = $this->em->createQueryBuilder()->select('d')->from(EventManagerDesignation::class, 'd')
            ->where('IDENTITY(d.participation) = :p')->orderBy('d.designatedAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setParameter('p', $participationId, 'uuid')->getQuery()->getResult();

        return $rows;
    }
}
