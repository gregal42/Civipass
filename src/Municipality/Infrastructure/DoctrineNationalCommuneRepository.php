<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NationalCommuneRepository::class)]
final readonly class DoctrineNationalCommuneRepository implements NationalCommuneRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(NationalCommune $commune): void
    {
        $this->entityManager->persist($commune);
    }

    public function findByInseeCode(InseeCode $code): ?NationalCommune
    {
        return $this->entityManager->getRepository(NationalCommune::class)->findOneBy(['inseeCode' => $code->value]);
    }

    public function findByInseeCodes(array $inseeCodes): array
    {
        if ([] === $inseeCodes) {
            return [];
        }
        $found = [];
        /** @var list<NationalCommune> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(NationalCommune::class, 'c')
            ->where('c.inseeCode IN (:codes)')
            ->setParameter('codes', $inseeCodes, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            $found[$row->inseeCode()->value] = $row;
        }

        return $found;
    }

    public function markSeen(array $inseeCodes, string $syncId, \DateTimeImmutable $at): void
    {
        if ([] === $inseeCodes) {
            return;
        }
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE municipality_national_commune SET last_seen_at = :at, last_sync_id = :sync WHERE insee_code IN (:codes)',
            ['at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'sync' => $syncId, 'codes' => $inseeCodes],
            ['codes' => ArrayParameterType::STRING],
        );
    }

    public function deactivateNotSeenIn(string $syncId, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE municipality_national_commune SET active = 0, deactivated_at = :now, updated_at = :now WHERE active = 1 AND last_sync_id <> :sync',
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'sync' => $syncId],
        );
    }

    public function countActive(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_national_commune WHERE active = 1');
    }
}
