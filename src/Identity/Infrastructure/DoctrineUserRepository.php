<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(UserRepository::class)]
final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(User $user): void
    {
        $this->entityManager->persist($user);
    }

    public function lock(User $user): User
    {
        $locked = $this->entityManager->find(User::class, $user->id(), LockMode::PESSIMISTIC_WRITE);
        if (null === $locked) {
            throw new OperationNotAllowed('Utilisateur introuvable.');
        }
        $this->entityManager->refresh($locked);

        return $locked;
    }

    public function find(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email->value]);
    }

    public function lockActiveSuperAdmins(): array
    {
        return array_values($this->entityManager->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.superAdmin = true')->andWhere('u.active = true')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult());
    }
}
