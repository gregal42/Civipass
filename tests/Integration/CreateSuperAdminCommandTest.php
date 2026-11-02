<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Application\UserManager;
use App\Identity\Domain\User;
use App\Identity\UI\Console\CreateSuperAdminCommand;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditOrigin;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateSuperAdminCommandTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    protected function tearDown(): void
    {
        putenv(CreateSuperAdminCommand::PASSWORD_ENV);
        parent::tearDown();
    }

    public function testCreatesAndPromotesANewAccountWithAnAuditedConsoleEntry(): void
    {
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $tester = $this->execute(['email' => ' Root@Example.TEST ', '--first-name' => 'Ada', '--last-name' => 'Lovelace'], interactive: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $user = $this->findUser('root@example.test');
        self::assertTrue($user->isSuperAdmin());
        self::assertTrue($user->isActive());
        self::assertTrue($this->service(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay(), 'Le mot de passe ne doit jamais être affiché.');

        $this->em->clear();
        $logs = $this->service(AuditLogRepository::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame(AuditAction::UserSuperAdminChanged, $logs[0]->action());
        self::assertSame(AuditOrigin::Console, $logs[0]->origin());
        self::assertNull($logs[0]->actorId());
        self::assertSame(['superAdmin' => false], $logs[0]->previousValue());
        self::assertSame(['superAdmin' => true], $logs[0]->newValue());
    }

    public function testInteractiveModeAsksForAHiddenPasswordWithConfirmation(): void
    {
        $tester = $this->execute(['email' => 'root@example.test'], interactive: true, inputs: ['Ada', 'Lovelace', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($this->findUser('root@example.test')->isSuperAdmin());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    public function testMismatchedConfirmationCreatesNothing(): void
    {
        $tester = $this->execute(['email' => 'root@example.test'], interactive: true, inputs: ['Ada', 'Lovelace', self::PASSWORD, 'another password!']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testThePasswordIsNeverAnArgumentOrAnOption(): void
    {
        $definition = $this->command()->getDefinition();

        self::assertFalse($definition->hasOption('password'));
        self::assertSame(['email'], array_keys($definition->getArguments()));
        self::assertEqualsCanonicalizing(['first-name', 'last-name'], array_keys(array_diff_key($definition->getOptions(), array_flip(['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'silent']))));
    }

    public function testWeakPasswordIsRefusedNonInteractively(): void
    {
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'=short');

        $tester = $this->execute(['email' => 'root@example.test', '--first-name' => 'A', '--last-name' => 'B'], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testNonInteractiveModeWithoutThePasswordVariableIsRefused(): void
    {
        $tester = $this->execute(['email' => 'root@example.test', '--first-name' => 'A', '--last-name' => 'B'], interactive: false);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString(CreateSuperAdminCommand::PASSWORD_ENV, $tester->getDisplay());
    }

    public function testRefusesWhenAnotherActiveSuperAdminExists(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('existing@example.test')->superAdmin()->build());
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $tester = $this->execute(['email' => 'new@example.test', '--first-name' => 'A', '--last-name' => 'B'], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testRefusesToPromoteAnExistingAccountWhenASuperAdminIsActive(): void
    {
        $this->save(
            UserBuilder::aUser()->withEmail('existing@example.test')->superAdmin()->build(),
            UserBuilder::aUser()->withEmail('agent@example.test')->build(),
        );

        $tester = $this->execute(['email' => 'agent@example.test'], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFalse($this->findUser('agent@example.test')->isSuperAdmin());
    }

    public function testIsIdempotentForTheAccountAlreadyInitialized(): void
    {
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'='.self::PASSWORD);
        $args = ['email' => 'root@example.test', '--first-name' => 'A', '--last-name' => 'B'];
        $this->execute($args, interactive: false);

        $second = $this->execute($args, interactive: false);

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'), 'Aucune entrée d\'audit pour un non-changement.');
    }

    public function testPromotesAnExistingActiveAccountWithoutTouchingItsPassword(): void
    {
        $user = $this->service(UserManager::class)->createUser('agent@example.test', self::PASSWORD, 'A', 'B');
        $hash = $user->getPassword();

        $tester = $this->execute(['email' => 'agent@example.test'], interactive: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $promoted = $this->findUser('agent@example.test');
        self::assertTrue($promoted->isSuperAdmin());
        self::assertSame($hash, $promoted->getPassword());
    }

    public function testRefusesAnExistingDisabledAccount(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('off@example.test')->inactive()->build());

        $tester = $this->execute(['email' => 'off@example.test'], interactive: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('désactivé', $tester->getDisplay());
        self::assertFalse($this->findUser('off@example.test')->isSuperAdmin());
    }

    public function testRecoveryIsPossibleWhenOnlyInactiveSuperAdminsRemain(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('old@example.test')->superAdmin()->inactive()->build());
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $tester = $this->execute(['email' => 'new@example.test', '--first-name' => 'A', '--last-name' => 'B'], interactive: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue($this->findUser('new@example.test')->isSuperAdmin());
    }

    public function testItIsNotABypassOfTheLastSuperAdminProtection(): void
    {
        putenv(CreateSuperAdminCommand::PASSWORD_ENV.'='.self::PASSWORD);
        $this->execute(['email' => 'root@example.test', '--first-name' => 'A', '--last-name' => 'B'], interactive: false);
        $root = $this->findUser('root@example.test');

        $this->expectException(OperationNotAllowed::class);
        $this->service(UserManager::class)->revokeSuperAdmin($root, null);
    }

    /**
     * @param array<string, string> $input
     * @param list<string>          $inputs
     */
    private function execute(array $input, bool $interactive, array $inputs = []): CommandTester
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs($inputs);
        $tester->execute($input, ['interactive' => $interactive]);
        $this->em->clear();

        return $tester;
    }

    private function command(): CreateSuperAdminCommand
    {
        return $this->service(CreateSuperAdminCommand::class);
    }

    private function findUser(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, $email);

        return $user;
    }
}
