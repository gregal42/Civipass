<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\RequestIdempotencyKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PermitRequestRepository::class)]
final readonly class DoctrinePermitRequestRepository implements PermitRequestRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PermitRequest $request, RequestIdempotencyKey $key): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->persist($key);
    }

    public function findByIdempotencyKey(Uuid $eventId, string $keyHash): ?PermitRequest
    {
        $request = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PermitRequest::class, 'r')
            ->join(RequestIdempotencyKey::class, 'k', 'WITH', 'k.request = r')
            ->where('IDENTITY(k.event) = :event')
            ->andWhere('k.keyHash = :hash')
            ->setParameter('event', $eventId, 'uuid')
            ->setParameter('hash', $keyHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $request instanceof PermitRequest ? $request : null;
    }
}
