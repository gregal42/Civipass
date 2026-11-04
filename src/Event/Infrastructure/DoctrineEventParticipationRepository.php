<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventParticipationRepository::class)]
final readonly class DoctrineEventParticipationRepository implements EventParticipationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(EventParticipation $participation): void
    {
        $this->entityManager->persist($participation);
    }

    public function find(Uuid $id): ?EventParticipation
    {
        return $this->entityManager->find(EventParticipation::class, $id);
    }

    public function findForMunicipality(Uuid $participationId, Uuid $municipalityId): ?EventParticipation
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(EventParticipation::class, 'p')
            ->where('p.id = :participation')
            ->andWhere('IDENTITY(p.municipality) = :municipality')
            ->setParameter('participation', $participationId, UuidType::NAME)
            ->setParameter('municipality', $municipalityId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByEventAndMunicipality(Uuid $eventId, Uuid $municipalityId): ?EventParticipation
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(EventParticipation::class, 'p')
            ->where('IDENTITY(p.event) = :event')
            ->andWhere('IDENTITY(p.municipality) = :municipality')
            ->setParameter('event', $eventId, UuidType::NAME)
            ->setParameter('municipality', $municipalityId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByEvent(Uuid $eventId): array
    {
        /* @var list<EventParticipation> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(EventParticipation::class, 'p')
            ->where('IDENTITY(p.event) = :event')
            ->setParameter('event', $eventId, UuidType::NAME)
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function lockByEvent(Uuid $eventId): array
    {
        // 1) Verrou des SEULES lignes de l'événement, par identifiant croissant. L'index sur `event_id` est IMPOSÉ : laissé à son choix,
        //    l'optimiseur MySQL peut balayer la clé primaire pour satisfaire `ORDER BY id` (selon les statistiques de la table) et un
        //    balayage verrouillant bloquerait aussi les participations d'AUTRES événements. Avec l'index (event_id, id) l'ordre est
        //    celui de l'index et seules les lignes de l'événement sont verrouillées.
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM event_participation FORCE INDEX (IDX_8F0C52E371F7E88B) WHERE event_id = :event ORDER BY id FOR UPDATE',
            ['event' => $eventId->toBinary()],
        );
        if ([] === $ids) {
            return [];
        }

        // 2) Entités relues sous ce verrou (déjà acquis) : état courant, jamais l'instantané, dans le même ordre.
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(EventParticipation::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', array_map('strval', $ids), ArrayParameterType::STRING)
            ->orderBy('p.id', 'ASC')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        /* @var list<EventParticipation> */
        return $query->getResult();
    }

    public function lock(Uuid $id): ?EventParticipation
    {
        // find() relit déjà l'entité sous verrou ; pas de refresh() simple (voir EventRepository::lock).
        return $this->entityManager->find(EventParticipation::class, $id, LockMode::PESSIMISTIC_WRITE);
    }
}
