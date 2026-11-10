<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\PermitRevisionVehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PermitRevisionRepository::class)]
final readonly class DoctrinePermitRevisionRepository implements PermitRevisionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PermitRevision $revision): void
    {
        $this->entityManager->persist($revision);
    }

    public function lockedByBatch(Uuid $permitId, Uuid $batchId): ?PermitRevision
    {
        $binary = $this->entityManager->getConnection()->fetchOne('SELECT id FROM permit_revision WHERE permit_id = :p AND batch_id = :b ORDER BY occurred_at, id LIMIT 1 FOR UPDATE', ['p' => $permitId->toBinary(), 'b' => $batchId->toBinary()]);

        return false === $binary ? null : $this->entityManager->find(PermitRevision::class, Uuid::fromBinary($binary), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    }

    public function lockedTokenReplacementTimes(Uuid $permitId): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn("SELECT occurred_at FROM permit_revision WHERE permit_id = :p AND type = 'TOKEN_REPLACED' ORDER BY occurred_at DESC, id DESC FOR UPDATE", ['p' => $permitId->toBinary()]);

        return array_map(static fn (mixed $at): \DateTimeImmutable => new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')), $rows);
    }

    public function countTokenReplacementsByActorSince(Uuid $userId, \DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_revision WHERE actor_user_id = :u AND type = 'TOKEN_REPLACED' AND occurred_at >= :s", ['u' => $userId->toBinary(), 's' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }

    public function addVehicleLine(PermitRevisionVehicle $line): void
    {
        $this->entityManager->persist($line);
    }

    public function vehicleLines(Uuid $permitId): array
    {
        /** @var list<PermitRevisionVehicle> $lines */
        $lines = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(PermitRevisionVehicle::class, 'l')
            ->join('l.revision', 'r')
            ->where('IDENTITY(r.permit) = :permit')
            ->setParameter('permit', $permitId, 'uuid')
            ->getQuery()
            ->getResult();
        $byRevision = [];
        foreach ($lines as $line) {
            $byRevision[$line->revision()->id()->toRfc4122()] = $line;
        }

        return $byRevision;
    }

    public function forPermit(Uuid $permitId): array
    {
        /** @var list<PermitRevision> $revisions */
        $revisions = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PermitRevision::class, 'r')
            ->where('IDENTITY(r.permit) = :permit')
            ->setParameter('permit', $permitId, 'uuid')
            ->orderBy('r.occurredAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $revisions;
    }
}
