<?php

declare(strict_types=1);

namespace App\Control\Infrastructure;

use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Control\Domain\DeactivationReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ControlDeviceRepository::class)]
final readonly class DoctrineControlDeviceRepository implements ControlDeviceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(ControlDevice $device): void
    {
        $this->entityManager->persist($device);
    }

    public function find(Uuid $id): ?ControlDevice
    {
        return $this->entityManager->find(ControlDevice::class, $id);
    }

    public function findBySecretHash(string $secretHash): ?ControlDevice
    {
        return $this->entityManager->getRepository(ControlDevice::class)->findOneBy(['secretHash' => $secretHash]);
    }

    /**
     * @return list<ControlDevice>
     */
    public function listFor(Uuid $municipalityId, Uuid $eventId, int $limit = 100): array
    {
        /** @var list<ControlDevice> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d', 'a')->from(ControlDevice::class, 'd')->join('d.activation', 'a')
            ->where('IDENTITY(a.municipality) = :m')->andWhere('IDENTITY(a.event) = :e')
            ->setParameter('m', $municipalityId, 'uuid')->setParameter('e', $eventId, 'uuid')
            ->orderBy('d.registeredAt', 'DESC')->addOrderBy('d.id', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    public function countPending(Uuid $municipalityId, Uuid $eventId, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM control_device d JOIN control_activation a ON a.id = d.activation_id WHERE a.municipality_id = :m AND a.event_id = :e AND d.status = 'PENDING' AND d.expires_at > :now",
            ['m' => $municipalityId->toBinary(), 'e' => $eventId->toBinary(), 'now' => self::utc($now)],
        );
    }

    public function approve(Uuid $id, string $pairingCode, Uuid $by, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            "UPDATE control_device SET status = 'ACTIVE', approved_at = :now, approved_by = :by, expires_at = :exp, pairing_code = NULL WHERE id = :id AND status = 'PENDING' AND expires_at > :now AND pairing_code = :code",
            ['now' => self::utc($now), 'by' => $by->toBinary(), 'exp' => self::utc($expiresAt), 'id' => $id->toBinary(), 'code' => $pairingCode],
        );
    }

    public function deactivateIfActive(Uuid $id, ?Uuid $by, DeactivationReason $reason, \DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            "UPDATE control_device SET status = 'DEACTIVATED', deactivated_at = :now, deactivated_by = :by, deactivation_reason = :reason, pairing_code = NULL WHERE id = :id AND status <> 'DEACTIVATED'",
            ['now' => self::utc($now), 'by' => $by?->toBinary(), 'reason' => $reason->value, 'id' => $id->toBinary()],
        );
    }

    public function renew(Uuid $id, \DateTimeImmutable $newExpiresAt): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            "UPDATE control_device SET expires_at = :exp WHERE id = :id AND status = 'ACTIVE'",
            ['exp' => self::utc($newExpiresAt), 'id' => $id->toBinary()],
        );
    }

    public function recordSync(Uuid $id, \DateTimeImmutable $now, string $snapshotHash): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE control_device SET last_sync_at = :now, last_snapshot_hash = :hash, sync_count = sync_count + 1 WHERE id = :id AND status = 'ACTIVE'",
            ['now' => self::utc($now), 'hash' => $snapshotHash, 'id' => $id->toBinary()],
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
