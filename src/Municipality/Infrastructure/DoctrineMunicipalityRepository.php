<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(MunicipalityRepository::class)]
final readonly class DoctrineMunicipalityRepository implements MunicipalityRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Municipality $municipality): void
    {
        $this->entityManager->persist($municipality);
    }

    public function find(Uuid $id): ?Municipality
    {
        return $this->entityManager->find(Municipality::class, $id);
    }

    public function findByInseeCode(InseeCode $code): ?Municipality
    {
        return $this->entityManager->getRepository(Municipality::class)->findOneBy(['inseeCode' => $code->value]);
    }

    public function lock(Uuid $id): ?Municipality
    {
        // find() relit déjà l'entité sous verrou ; un refresh() simple relirait l'instantané REPEATABLE READ (état périmé).
        return $this->entityManager->find(Municipality::class, $id, LockMode::PESSIMISTIC_WRITE);
    }
}
