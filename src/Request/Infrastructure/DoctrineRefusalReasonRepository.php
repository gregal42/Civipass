<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Domain\RefusalReason;
use App\Request\Domain\RefusalReasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RefusalReasonRepository::class)]
final readonly class DoctrineRefusalReasonRepository implements RefusalReasonRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(RefusalReason $reason): void
    {
        $this->entityManager->persist($reason);
    }

    public function findByCode(string $code): ?RefusalReason
    {
        return $this->entityManager->getRepository(RefusalReason::class)->findOneBy(['code' => $code]);
    }

    public function active(): array
    {
        return $this->entityManager->getRepository(RefusalReason::class)->findBy(['active' => true], ['position' => 'ASC', 'code' => 'ASC']);
    }
}
