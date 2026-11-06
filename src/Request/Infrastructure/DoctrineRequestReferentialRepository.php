<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestReferentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RequestReferentialRepository::class)]
final readonly class DoctrineRequestReferentialRepository implements RequestReferentialRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function addMotif(RequestMotif $motif): void
    {
        $this->entityManager->persist($motif);
    }

    public function findMotifByCode(string $code): ?RequestMotif
    {
        return $this->entityManager->getRepository(RequestMotif::class)->findOneBy(['code' => $code]);
    }

    public function addCategory(BeneficiaryCategory $category): void
    {
        $this->entityManager->persist($category);
    }

    public function findCategoryByCode(string $code): ?BeneficiaryCategory
    {
        return $this->entityManager->getRepository(BeneficiaryCategory::class)->findOneBy(['code' => $code]);
    }

    public function activeMotifs(): array
    {
        return $this->entityManager->getRepository(RequestMotif::class)->findBy(['active' => true], ['position' => 'ASC', 'code' => 'ASC']);
    }

    public function activeCategories(): array
    {
        return $this->entityManager->getRepository(BeneficiaryCategory::class)->findBy(['active' => true], ['position' => 'ASC', 'code' => 'ASC']);
    }
}
