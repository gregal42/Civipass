<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Municipality\Domain\Municipality;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(MunicipalityAdminInvitationRepository::class)]
final readonly class DoctrineMunicipalityAdminInvitationRepository implements MunicipalityAdminInvitationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(MunicipalityAdminInvitation $invitation): void
    {
        $this->entityManager->persist($invitation);
    }

    public function find(Uuid $id): ?MunicipalityAdminInvitation
    {
        return $this->entityManager->find(MunicipalityAdminInvitation::class, $id);
    }

    public function findByTokenHash(string $tokenHash): ?MunicipalityAdminInvitation
    {
        return $this->entityManager->getRepository(MunicipalityAdminInvitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findByMunicipality(Municipality $municipality): array
    {
        /** @var list<MunicipalityAdminInvitation> $all */
        $all = $this->entityManager->getRepository(MunicipalityAdminInvitation::class)->findBy(['municipality' => $municipality], ['id' => 'DESC']);

        return $all;
    }

    public function revokeOpen(Uuid $municipalityId, string $recipientEmail, Uuid $revokedBy, \DateTimeImmutable $now, ?Uuid $exceptId = null): int
    {
        $sql = 'UPDATE municipality_admin_invitation SET revoked_at = :now, revoked_by = :by WHERE municipality_id = :municipality AND recipient_email = :email AND consumed_at IS NULL AND revoked_at IS NULL';
        $params = ['now' => self::utc($now), 'by' => $revokedBy->toBinary(), 'municipality' => $municipalityId->toBinary(), 'email' => $recipientEmail];
        if (null !== $exceptId) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId->toBinary();
        }

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $params);
    }

    public function revokeIfOpen(Uuid $id, Uuid $revokedBy, \DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE municipality_admin_invitation SET revoked_at = :now, revoked_by = :by WHERE id = :id AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            ['now' => self::utc($now), 'by' => $revokedBy->toBinary(), 'id' => $id->toBinary()],
        );
    }

    public function consume(Uuid $id, Uuid $userId, \DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE municipality_admin_invitation SET consumed_at = :now, consumed_by = :user WHERE id = :id AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            ['now' => self::utc($now), 'user' => $userId->toBinary(), 'id' => $id->toBinary()],
        );
    }

    public function countPending(Uuid $municipalityId, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM municipality_admin_invitation WHERE municipality_id = :municipality AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            ['municipality' => $municipalityId->toBinary(), 'now' => self::utc($now)],
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
