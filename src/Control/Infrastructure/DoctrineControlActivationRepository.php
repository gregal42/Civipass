<?php

declare(strict_types=1);

namespace App\Control\Infrastructure;

use App\Control\Domain\ControlActivation;
use App\Control\Domain\ControlActivationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ControlActivationRepository::class)]
final readonly class DoctrineControlActivationRepository implements ControlActivationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(ControlActivation $activation): void
    {
        $this->entityManager->persist($activation);
    }

    public function find(Uuid $id): ?ControlActivation
    {
        return $this->entityManager->find(ControlActivation::class, $id);
    }

    /**
     * @return list<ControlActivation>
     */
    public function listFor(Uuid $municipalityId, Uuid $eventId, int $limit = 20): array
    {
        /** @var list<ControlActivation> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a')->from(ControlActivation::class, 'a')
            ->where('IDENTITY(a.municipality) = :m')->andWhere('IDENTITY(a.event) = :e')
            ->setParameter('m', $municipalityId, 'uuid')->setParameter('e', $eventId, 'uuid')
            ->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    public function countUsable(Uuid $municipalityId, Uuid $eventId, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM control_activation WHERE municipality_id = :m AND event_id = :e AND revoked_at IS NULL AND expires_at > :now AND uses_count < max_devices',
            ['m' => $municipalityId->toBinary(), 'e' => $eventId->toBinary(), 'now' => self::utc($now)],
        );
    }

    public function consumeUse(string $codeHash, \DateTimeImmutable $now): ?Uuid
    {
        $connection = $this->entityManager->getConnection();
        $affected = (int) $connection->executeStatement(
            'UPDATE control_activation SET uses_count = uses_count + 1 WHERE code_hash = :hash AND revoked_at IS NULL AND expires_at > :now AND uses_count < max_devices',
            ['hash' => $codeHash, 'now' => self::utc($now)],
        );
        if (1 !== $affected) {
            return null;
        }
        $id = $connection->fetchOne('SELECT id FROM control_activation WHERE code_hash = :hash', ['hash' => $codeHash]);

        return false === $id ? null : Uuid::fromBinary((string) $id);
    }

    public function revokeIfOpen(Uuid $id, Uuid $by, \DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE control_activation SET revoked_at = :now, revoked_by = :by WHERE id = :id AND revoked_at IS NULL AND expires_at > :now',
            ['now' => self::utc($now), 'by' => $by->toBinary(), 'id' => $id->toBinary()],
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
