<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\PasswordResetRequest;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PasswordResetRepository::class)]
final readonly class DoctrinePasswordResetRepository implements PasswordResetRepository
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PasswordResetRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        $request = $this->entityManager->getRepository(PasswordResetRequest::class)->findOneBy(['tokenHash' => $tokenHash]);
        if (null !== $request) {
            // consume() et invalidateOpenRequestsOf() écrivent en SQL direct : l'objet en mémoire peut être périmé.
            $this->entityManager->refresh($request);
        }

        return $request;
    }

    public function consume(Uuid $requestId, \DateTimeImmutable $now): bool
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE identity_password_reset_request SET used_at = :now WHERE id = :id AND used_at IS NULL AND expires_at > :now',
            ['now' => $this->utc($now), 'id' => $requestId->toBinary()],
        );

        return 1 === $affected;
    }

    public function invalidateOpenRequestsOf(User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE identity_password_reset_request SET used_at = :now WHERE user_id = :user AND used_at IS NULL',
            ['now' => $this->utc($now), 'user' => $user->id()->toBinary()],
        );
    }

    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }
}
