<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\CwsPermissionGrant;
use App\Identity\Domain\CwsPermissionGrantRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(CwsPermissionGrantRepository::class)]
final readonly class DoctrineCwsPermissionGrantRepository implements CwsPermissionGrantRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(CwsPermissionGrant $grant): void
    {
        $this->entityManager->persist($grant);
    }

    public function findActive(Uuid $userId, CwsPermission $permission): ?CwsPermissionGrant
    {
        return $this->query($userId, $permission, null);
    }

    public function lockActive(Uuid $userId, CwsPermission $permission): ?CwsPermissionGrant
    {
        $grant = $this->query($userId, $permission, LockMode::PESSIMISTIC_WRITE);
        if (null !== $grant) {
            $this->entityManager->refresh($grant);
        }

        return $grant?->isActive() ? $grant : null;
    }

    public function all(): array
    {
        /** @var list<CwsPermissionGrant> $grants */
        $grants = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(CwsPermissionGrant::class, 'g')
            ->orderBy('g.grantedAt', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $grants;
    }

    private function query(Uuid $userId, CwsPermission $permission, ?LockMode $lock): ?CwsPermissionGrant
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(CwsPermissionGrant::class, 'g')
            ->where('IDENTITY(g.user) = :user')
            ->andWhere('g.permission = :permission')
            ->andWhere('g.activeKey IS NOT NULL')
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('permission', $permission)
            ->setMaxResults(1)
            ->getQuery();
        if (null !== $lock) {
            $query->setLockMode($lock);
        }
        $grant = $query->getOneOrNullResult();

        return $grant instanceof CwsPermissionGrant ? $grant : null;
    }
}
