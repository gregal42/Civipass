<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Identity\Domain\User;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(MembershipRepository::class)]
final readonly class DoctrineMembershipRepository implements MembershipRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(MunicipalityMembership $membership): void
    {
        $this->entityManager->persist($membership);
    }

    public function find(Uuid $id): ?MunicipalityMembership
    {
        return $this->entityManager->find(MunicipalityMembership::class, $id);
    }

    public function findOneByUserAndMunicipality(User $user, Municipality $municipality): ?MunicipalityMembership
    {
        return $this->entityManager->getRepository(MunicipalityMembership::class)
            ->findOneBy(['user' => $user, 'municipality' => $municipality]);
    }

    public function countEffectiveAdmins(Municipality $municipality, ?Uuid $excludingMembershipId = null, ?Uuid $excludingUserId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id'
            .' WHERE mm.municipality_id = :municipality AND mm.active = 1 AND u.active = 1 AND JSON_CONTAINS(mm.roles, :role)';
        $params = ['municipality' => $municipality->id()->toBinary(), 'role' => json_encode(MunicipalityRole::MunicipalityAdmin->value, \JSON_THROW_ON_ERROR)];
        if (null !== $excludingMembershipId) {
            $sql .= ' AND mm.id <> :excludedMembership';
            $params['excludedMembership'] = $excludingMembershipId->toBinary();
        }
        if (null !== $excludingUserId) {
            $sql .= ' AND u.id <> :excludedUser';
            $params['excludedUser'] = $excludingUserId->toBinary();
        }

        // Lecture COURANTE (FOR SHARE), jamais l'instantané REPEATABLE READ pris par une lecture antérieure de la transaction : après
        // l'attente du verrou de la commune, on doit voir ce qu'une opération concurrente vient de valider (sinon deux retraits
        // simultanés laisseraient la commune sans administrateur : constaté par CwsConcurrencyTest).
        return (int) $this->entityManager->getConnection()->fetchOne($sql.' FOR SHARE', $params);
    }

    public function findActiveMunicipalityIdsAdministeredBy(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT mm.municipality_id FROM municipality_membership mm JOIN municipality c ON c.id = mm.municipality_id'
            .' WHERE mm.user_id = :user AND mm.active = 1 AND c.status = :status AND JSON_CONTAINS(mm.roles, :role)'
            .' ORDER BY mm.municipality_id',
            [
                'user' => $user->id()->toBinary(),
                'status' => MunicipalityStatus::Active->value,
                'role' => json_encode(MunicipalityRole::MunicipalityAdmin->value, \JSON_THROW_ON_ERROR),
            ],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    public function findActiveByUser(User $user): array
    {
        return $this->entityManager->getRepository(MunicipalityMembership::class)
            ->findBy(['user' => $user, 'active' => true]);
    }

    public function findByMunicipality(Municipality $municipality): array
    {
        return $this->entityManager->getRepository(MunicipalityMembership::class)
            ->findBy(['municipality' => $municipality]);
    }
}
