<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\TwoFactorBackupCode;
use App\Identity\Domain\TwoFactorCredential;
use App\Identity\Domain\TwoFactorRepository;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TwoFactorRepository::class)]
final readonly class DoctrineTwoFactorRepository implements TwoFactorRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByUser(User $user): ?TwoFactorCredential
    {
        $credential = $this->entityManager->getRepository(TwoFactorCredential::class)->findOneBy(['user' => $user]);
        if (null !== $credential) {
            $this->entityManager->refresh($credential); // acceptStep() écrit en SQL direct : l'objet en mémoire peut être périmé
        }

        return $credential;
    }

    public function add(TwoFactorCredential $credential): void
    {
        $this->entityManager->persist($credential);
    }

    public function addBackupCode(TwoFactorBackupCode $code): void
    {
        $this->entityManager->persist($code);
    }

    public function acceptStep(TwoFactorCredential $credential, int $step): bool
    {
        return 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE identity_two_factor SET last_used_step = :step WHERE id = :id AND confirmed_at IS NOT NULL AND (last_used_step IS NULL OR last_used_step < :step)',
            ['step' => $step, 'id' => $credential->id()->toBinary()],
        );
    }

    public function consumeBackupCode(TwoFactorCredential $credential, string $codeHash, \DateTimeImmutable $now): bool
    {
        return 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE identity_two_factor_backup_code SET used_at = :now WHERE credential_id = :c AND code_hash = :h AND used_at IS NULL',
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'c' => $credential->id()->toBinary(), 'h' => $codeHash],
        );
    }

    public function remainingBackupCodes(TwoFactorCredential $credential): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM identity_two_factor_backup_code WHERE credential_id = :c AND used_at IS NULL',
            ['c' => $credential->id()->toBinary()],
        );
    }

    public function remove(User $user): bool
    {
        $connection = $this->entityManager->getConnection();
        $id = $connection->fetchOne('SELECT id FROM identity_two_factor WHERE user_id = :u', ['u' => $user->id()->toBinary()]);
        if (false === $id) {
            return false;
        }
        $connection->executeStatement('DELETE FROM identity_two_factor_backup_code WHERE credential_id = :c', ['c' => $id]);
        $connection->executeStatement('DELETE FROM identity_two_factor WHERE id = :c', ['c' => $id]);
        // Seul le second facteur supprimé quitte l'unité de travail (jamais un `clear()` complet : l'utilisateur courant et les autres objets restent gérés).
        $unit = $this->entityManager->getUnitOfWork();
        foreach ($unit->getIdentityMap()[TwoFactorBackupCode::class] ?? [] as $code) {
            if ($code instanceof TwoFactorBackupCode && $code->credential()->id()->equals(Uuid::fromBinary((string) $id))) {
                $this->entityManager->detach($code);
            }
        }
        $managed = $unit->tryGetById(Uuid::fromBinary((string) $id)->toRfc4122(), TwoFactorCredential::class);
        if (\is_object($managed)) {
            $this->entityManager->detach($managed);
        }

        return true;
    }
}
