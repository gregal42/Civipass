<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventRepository;
use App\Event\Domain\PublicReference;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventRepository::class)]
final readonly class DoctrineEventRepository implements EventRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Event $event): void
    {
        $this->entityManager->persist($event);
    }

    public function find(Uuid $id): ?Event
    {
        return $this->entityManager->find(Event::class, $id);
    }

    public function findByPublicReference(PublicReference $reference): ?Event
    {
        return $this->entityManager->getRepository(Event::class)->findOneBy(['publicReference' => $reference->value]);
    }

    public function findAccessibleTo(Uuid $eventId, Uuid $municipalityId): ?Event
    {
        return $this->accessibleQuery($municipalityId)
            ->andWhere('e.id = :event')
            ->setParameter('event', $eventId, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function listForMunicipality(Uuid $municipalityId, int $limit = 100): array
    {
        /* @var list<Event> */
        return $this->accessibleQuery($municipalityId)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function lock(Uuid $id): ?Event
    {
        return $this->entityManager->find(Event::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function lockShared(Uuid $id): ?Event
    {
        return $this->entityManager->find(Event::class, $id, LockMode::PESSIMISTIC_READ);
    }

    private function accessibleQuery(Uuid $municipalityId): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('e')
            ->from(Event::class, 'e')
            ->where('IDENTITY(e.organizerMunicipality) = :municipality OR EXISTS ('
                .'SELECT 1 FROM '.EventParticipation::class.' p WHERE p.event = e AND IDENTITY(p.municipality) = :municipality)')
            ->setParameter('municipality', $municipalityId, UuidType::NAME);
    }
}
