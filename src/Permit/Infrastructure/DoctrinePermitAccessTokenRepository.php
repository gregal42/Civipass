<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Domain\PermitAccessToken;
use App\Permit\Domain\PermitAccessTokenRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PermitAccessTokenRepository::class)]
final readonly class DoctrinePermitAccessTokenRepository implements PermitAccessTokenRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PermitAccessToken $token): void
    {
        $this->entityManager->persist($token);
    }

    public function findByHash(string $hash): ?PermitAccessToken
    {
        return $this->entityManager->getRepository(PermitAccessToken::class)->findOneBy(['secretHash' => $hash]);
    }

    public function lockForPermit(Uuid $tokenId, Uuid $permitId): ?PermitAccessToken
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(PermitAccessToken::class, 't')
            ->where('t.id = :id')
            ->andWhere('IDENTITY(t.permit) = :permit')
            ->setParameter('id', $tokenId, 'uuid')
            ->setParameter('permit', $permitId, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $token = $query->getOneOrNullResult();

        return $token instanceof PermitAccessToken ? $token : null;
    }

    public function find(Uuid $tokenId): ?PermitAccessToken
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(PermitAccessToken::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $tokenId, 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $token = $query->getOneOrNullResult();

        return $token instanceof PermitAccessToken ? $token : null;
    }

    public function lock(Uuid $tokenId): ?PermitAccessToken
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(PermitAccessToken::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $tokenId, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $token = $query->getOneOrNullResult();

        return $token instanceof PermitAccessToken ? $token : null;
    }

    public function findByPermit(Uuid $permitId): array
    {
        /** @var list<PermitAccessToken> $tokens */
        $tokens = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(PermitAccessToken::class, 't')
            ->where('IDENTITY(t.permit) = :permit')
            ->setParameter('permit', $permitId, 'uuid')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }
}
