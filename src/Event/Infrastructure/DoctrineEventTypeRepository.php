<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\EventType;
use App\Event\Domain\EventTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(EventTypeRepository::class)]
final readonly class DoctrineEventTypeRepository implements EventTypeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(EventType $type): void
    {
        $this->entityManager->persist($type);
    }

    public function find(Uuid $id): ?EventType
    {
        return $this->entityManager->find(EventType::class, $id);
    }

    public function findByCode(string $code): ?EventType
    {
        return $this->entityManager->getRepository(EventType::class)->findOneBy(['code' => $code]);
    }

    public function findActive(): array
    {
        /* @var list<EventType> */
        return $this->entityManager->getRepository(EventType::class)->findBy(['active' => true], ['sortOrder' => 'ASC', 'code' => 'ASC']);
    }
}
