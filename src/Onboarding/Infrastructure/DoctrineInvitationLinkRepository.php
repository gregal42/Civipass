<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Domain\InvitationLink;
use App\Onboarding\Domain\InvitationLinkKind;
use App\Onboarding\Domain\InvitationLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(InvitationLinkRepository::class)]
final readonly class DoctrineInvitationLinkRepository implements InvitationLinkRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(InvitationLink $link): void
    {
        $this->entityManager->persist($link);
    }

    public function find(Uuid $id): ?InvitationLink
    {
        return $this->entityManager->find(InvitationLink::class, $id);
    }

    public function findByTokenHash(string $tokenHash): ?InvitationLink
    {
        return $this->entityManager->getRepository(InvitationLink::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function revokeOpen(Uuid $participationId, InvitationLinkKind $kind, \DateTimeImmutable $now, ?Uuid $exceptId = null): int
    {
        $sql = 'UPDATE onboarding_invitation_link SET revoked_at = :now WHERE participation_id = :participation AND kind = :kind AND consumed_at IS NULL AND revoked_at IS NULL';
        $params = ['now' => self::utc($now), 'participation' => $participationId->toBinary(), 'kind' => $kind->value];
        if (null !== $exceptId) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId->toBinary();
        }

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $params);
    }

    public function revokeOpenOfEvent(Uuid $eventId, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE onboarding_invitation_link l JOIN event_participation p ON p.id = l.participation_id SET l.revoked_at = :now'
            .' WHERE p.event_id = :event AND l.consumed_at IS NULL AND l.revoked_at IS NULL',
            ['now' => self::utc($now), 'event' => $eventId->toBinary()],
        );
    }

    public function consume(Uuid $id, \DateTimeImmutable $now): bool
    {
        return 1 === (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE onboarding_invitation_link SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            ['now' => self::utc($now), 'id' => $id->toBinary()],
        );
    }

    public function latestContact(Uuid $participationId): ?InvitationLink
    {
        /** @var InvitationLink|null $link */
        $link = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(InvitationLink::class, 'l')
            ->where('IDENTITY(l.participation) = :participation')
            ->andWhere('l.kind = :kind')
            ->setParameter('participation', $participationId, 'uuid')
            ->setParameter('kind', InvitationLinkKind::Contact->value)
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $link;
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
