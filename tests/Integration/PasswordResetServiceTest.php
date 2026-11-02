<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Application\InvalidPasswordResetToken;
use App\Identity\Application\Notification\PasswordResetNotifier;
use App\Identity\Application\PasswordResetService;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Mail\DeferredMailQueue;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EmailAccess;
use App\Tests\Support\UserBuilder;
use App\Identity\Infrastructure\DoctrinePasswordResetRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetServiceTest extends DatabaseTestCase
{
    use EmailAccess;

    private const OLD = 'old password 123456';
    private const NEW = 'brand new password';

    private PasswordResetService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(PasswordResetService::class);
        $this->user = $this->service(\App\Identity\Application\UserManager::class)->createUser('user@example.test', self::OLD, 'Ada', 'Lovelace');
    }

    public function testRequestStoresOnlyTheHashAndSendsTheTokenOnlyByEmail(): void
    {
        $this->service->requestReset(' USER@Example.TEST ');
        $this->flushMails();

        $plain = $this->tokenFromLastEmail();
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM identity_password_reset_request');
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $plain), $rows[0]['token_hash']);
        self::assertStringNotContainsString($plain, json_encode($rows, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), 'Le jeton en clair ne doit exister nulle part en base.');
        self::assertNull($rows[0]['used_at']);
        self::assertTrue($this->service->isTokenUsable($plain));
    }

    public function testNoEffectNoEmailAndNoExceptionForUnknownInvalidOrDisabledAccounts(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('off@example.test')->inactive()->build());

        foreach (['nobody@example.test', 'pas un email', '', 'off@example.test'] as $email) {
            $this->service->requestReset($email);
        }
        $this->flushMails();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_password_reset_request'));
        self::assertCount(0, self::getMailerMessages());
    }

    public function testANewRequestInvalidatesThePreviousOne(): void
    {
        $this->service->requestReset('user@example.test');
        $this->flushMails();
        $first = $this->tokenFromLastEmail();

        $this->service->requestReset('user@example.test');
        $this->flushMails();
        $second = $this->tokenFromLastEmail();

        self::assertNotSame($first, $second);
        self::assertFalse($this->service->isTokenUsable($first));
        self::assertTrue($this->service->isTokenUsable($second));
    }

    public function testSuccessfulResetChangesThePasswordConsumesTheTokenAndIsAudited(): void
    {
        $plain = $this->issueToken();
        $other = $this->issueToken(); // invalide le précédent
        $hasher = $this->service(UserPasswordHasherInterface::class);

        $this->service->resetPassword($other, self::NEW);

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id());
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, self::NEW));
        self::assertFalse($hasher->isPasswordValid($user, self::OLD));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_password_reset_request WHERE used_at IS NULL'), 'Plus aucun jeton ouvert.');
        self::assertFalse($this->service->isTokenUsable($plain));
        self::assertFalse($this->service->isTokenUsable($other));

        $logs = array_values(array_filter(
            $this->service(AuditLogRepository::class)->findAll(),
            static fn ($l): bool => AuditAction::UserPasswordReset === $l->action(),
        ));
        self::assertCount(1, $logs);
        self::assertSame(AuditOrigin::User, $logs[0]->origin());
        self::assertTrue($user->id()->equals($logs[0]->actorId()));
        self::assertTrue($user->id()->equals($logs[0]->subjectId()));
        self::assertSame(['passwordReset' => true], $logs[0]->newValue());
        $serialized = json_encode([$logs[0]->newValue(), $logs[0]->previousValue()], \JSON_THROW_ON_ERROR);
        foreach ([self::NEW, self::OLD, $plain, $other] as $secret) {
            self::assertStringNotContainsString($secret, $serialized, 'Aucun secret dans l\'audit.');
        }
    }

    public function testTokenCannotBeReused(): void
    {
        $plain = $this->issueToken();
        $this->service->resetPassword($plain, self::NEW);

        try {
            $this->service->resetPassword($plain, 'another password!!');
            self::fail('Réutilisation du jeton : refus attendu.');
        } catch (InvalidPasswordResetToken) {
        }

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id());
        self::assertTrue($this->service(UserPasswordHasherInterface::class)->isPasswordValid($user, self::NEW), 'Le second appel ne doit rien changer.');
    }

    public function testExpiredTokenIsRefused(): void
    {
        $plain = $this->issueToken();
        $this->em->getConnection()->executeStatement('UPDATE identity_password_reset_request SET expires_at = ?', ['2000-01-01 00:00:00']);
        $this->em->clear();

        self::assertFalse($this->service->isTokenUsable($plain));
        $this->expectException(InvalidPasswordResetToken::class);
        $this->service->resetPassword($plain, self::NEW);
    }

    public function testUnknownAndMalformedTokensGiveTheSameRefusal(): void
    {
        foreach ([PasswordResetToken::generate()->plain, 'garbage', ''] as $token) {
            try {
                $this->service->resetPassword($token, self::NEW);
                self::fail('Refus attendu.');
            } catch (InvalidPasswordResetToken $e) {
                self::assertSame('Ce lien de réinitialisation est invalide ou a expiré.', $e->getMessage());
            }
        }
    }

    public function testWeakPasswordIsRefusedAndDoesNotConsumeTheToken(): void
    {
        $plain = $this->issueToken();

        try {
            $this->service->resetPassword($plain, 'short');
            self::fail('Politique : refus attendu.');
        } catch (DomainRuleViolation $e) {
            self::assertNotInstanceOf(InvalidPasswordResetToken::class, $e);
        }

        self::assertTrue($this->service->isTokenUsable($plain));
        $this->service->resetPassword($plain, self::NEW);
        $this->addToAssertionCount(1);
    }

    public function testMultibytePasswordOverTheByteLimitIsRefusedByThePolicyNotByTheHasher(): void
    {
        $plain = $this->issueToken();

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('octets');
        $this->service->resetPassword($plain, str_repeat('é', 2049));
    }

    public function testAccountDisabledAfterTheRequestCannotReset(): void
    {
        $plain = $this->issueToken();
        $this->em->getConnection()->executeStatement('UPDATE identity_user SET active = 0');
        $this->em->clear();

        self::assertFalse($this->service->isTokenUsable($plain));
        $this->expectException(InvalidPasswordResetToken::class);
        $this->service->resetPassword($plain, self::NEW);
    }

    public function testResetIsAtomicAFailingAuditRollsBackTokenConsumptionAndPasswordChange(): void
    {
        $plain = $this->issueToken();
        $hashBefore = (string) $this->em->getConnection()->fetchOne('SELECT password FROM identity_user');
        $failingAudit = new class () implements AuditTrail {
            public function record(AuditEvent $event): void
            {
                throw new \RuntimeException('audit indisponible');
            }
        };
        $service = new PasswordResetService(
            $this->service(TransactionRunner::class),
            $this->service(UserRepository::class),
            $this->service(PasswordResetRepository::class),
            $this->service(UserPasswordHasherInterface::class),
            $this->service(PasswordResetNotifier::class),
            $failingAudit,
            $this->service(ClockInterface::class),
        );

        try {
            $service->resetPassword($plain, self::NEW);
            self::fail('Exception attendue.');
        } catch (\RuntimeException) {
        }

        $connection = $this->em->getConnection();
        self::assertSame($hashBefore, $connection->fetchOne('SELECT password FROM identity_user'), 'Mot de passe inchangé.');
        self::assertNull($connection->fetchOne('SELECT used_at FROM identity_password_reset_request'), 'Jeton non consommé.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
        $this->flushMails();
        self::assertCount(1, self::getMailerMessages(), 'Aucun email « mot de passe modifié » en cas d\'échec (seul l\'email du lien existe).');
    }

    public function testConsumptionIsAtomicAndSingleWinner(): void
    {
        $plain = $this->issueToken();
        $requests = $this->service(PasswordResetRepository::class);
        $id = $requests->findByTokenHash(hash('sha256', $plain))->id();
        $now = new \DateTimeImmutable();

        self::assertTrue($requests->consume($id, $now));
        // Même avec un instant différent (un UPDATE sans condition used_at IS NULL modifierait la ligne et réussirait).
        self::assertFalse($requests->consume($id, $now->modify('+5 seconds')));
        self::assertFalse($requests->consume($id, $now));
    }

    public function testConcurrentConsumptionsAreSerializedByTheDatabaseAndHaveASingleWinner(): void
    {
        $plain = $this->issueToken();
        $requests = $this->service(PasswordResetRepository::class);
        $id = $requests->findByTokenHash(hash('sha256', $plain))->id();
        $connection = $this->em->getConnection();

        // Seconde unité de travail : autre connexion, autre EntityManager, MÊME code de dépôt que la production.
        $otherConnection = DriverManager::getConnection($connection->getParams());
        $otherConnection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $otherRequests = new DoctrinePasswordResetRepository(new EntityManager($otherConnection, $this->em->getConfiguration()));

        $connection->beginTransaction();
        try {
            self::assertTrue($requests->consume($id, new \DateTimeImmutable())); // verrou de ligne, non validé

            try {
                $otherRequests->consume($id, new \DateTimeImmutable('+1 minute'));
                self::fail('Le second consommateur doit attendre le verrou du premier.');
            } catch (LockWaitTimeoutException) {
            }
            $connection->commit();
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        // Après validation du premier : un seul gagnant, le second ne consomme rien.
        self::assertFalse($otherRequests->consume($id, new \DateTimeImmutable('+1 minute')));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM identity_password_reset_request WHERE used_at IS NOT NULL'));
        $otherConnection->close();
    }

    public function testConsumptionRefusesAnExpiredRequestAtTheBoundary(): void
    {
        $plain = $this->issueToken();
        $requests = $this->service(PasswordResetRepository::class);
        $request = $requests->findByTokenHash(hash('sha256', $plain));

        self::assertFalse($requests->consume($request->id(), $request->expiresAt()), 'expires_at > maintenant (strict).');
        self::assertTrue($requests->consume($request->id(), $request->expiresAt()->modify('-1 second')));
    }

    public function testNoEmailContainsAPassword(): void
    {
        $plain = $this->issueToken();
        $this->service->resetPassword($plain, self::NEW);
        $this->flushMails();

        foreach (self::emails() as $message) {
            foreach ([self::NEW, self::OLD] as $password) {
                self::assertStringNotContainsString($password, self::bodiesOf($message));
            }
        }
        self::assertCount(2, self::getMailerMessages(), 'Lien + notification de changement.');
    }

    private function issueToken(): string
    {
        $this->service->requestReset('user@example.test');
        $this->flushMails();

        return $this->tokenFromLastEmail();
    }

    private function flushMails(): void
    {
        $this->service(DeferredMailQueue::class)->flush();
    }

    private function tokenFromLastEmail(): string
    {
        $messages = self::emails();
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if (1 === preg_match('~/reset-password/([A-Za-z0-9_-]{43})~', (string) $messages[$i]->getTextBody(), $m)) {
                return $m[1];
            }
        }
        self::fail('Aucun lien de réinitialisation dans les emails.');
    }
}
