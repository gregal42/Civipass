<?php

declare(strict_types=1);

namespace App\Archive\Infrastructure;

use App\Archive\Domain\ArchiveConsultation;
use App\Archive\Domain\ArchiveConsultationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(ArchiveConsultationRepository::class)]
final readonly class DoctrineArchiveConsultationRepository implements ArchiveConsultationRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ArchiveConsultation $consultation): void
    {
        $this->em->persist($consultation);
    }

    public function find(Uuid $id): ?ArchiveConsultation
    {
        return $this->em->find(ArchiveConsultation::class, $id);
    }

    public function forMunicipality(Uuid $municipalityId, int $limit): array
    {
        /** @var list<ArchiveConsultation> $rows */
        $rows = $this->em->createQueryBuilder()->select('c')->from(ArchiveConsultation::class, 'c')->join('c.dossier', 'd')
            ->where('IDENTITY(d.municipality) = :m')->orderBy('c.occurredAt', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)
            ->setParameter('m', $municipalityId, 'uuid')->getQuery()->getResult();

        return $rows;
    }
}
