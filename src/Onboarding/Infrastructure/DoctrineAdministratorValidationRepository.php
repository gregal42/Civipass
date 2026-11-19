<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Domain\AdministratorValidation;
use App\Onboarding\Domain\AdministratorValidationRepository;
use App\Onboarding\Domain\AdministratorValidationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(AdministratorValidationRepository::class)]
final readonly class DoctrineAdministratorValidationRepository implements AdministratorValidationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(AdministratorValidation $validation): void
    {
        $this->entityManager->persist($validation);
    }

    public function find(Uuid $id): ?AdministratorValidation
    {
        return $this->entityManager->find(AdministratorValidation::class, $id);
    }

    public function pending(): array
    {
        return array_values($this->entityManager->getRepository(AdministratorValidation::class)->findBy(['status' => AdministratorValidationStatus::Pending], ['requestedAt' => 'ASC']));
    }
}
