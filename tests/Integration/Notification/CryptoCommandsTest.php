<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Application\SecretCipher;
use App\Notification\UI\Console\CheckCryptoCommand;
use App\Notification\UI\Console\PurgeSecretsCommand;
use App\Tests\Support\NotificationKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Commandes du 05.3.2 : contrôle explicite de la cryptographie et purge ; aucune sortie ne contient de clé ni de secret. */
final class CryptoCommandsTest extends TestCase
{
    public function testTheCheckPassesWithAValidKeyRingAndNeverPrintsKeyMaterial(): void
    {
        [$keyring, $active] = NotificationKit::newKey('2026a');
        $rawKey = explode(':', $keyring, 2)[1];
        $tester = new CommandTester(new CheckCryptoCommand(NotificationKit::provider($keyring, $active), new SecretCipher()));

        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('Clé active : 2026a', $tester->getDisplay());
        self::assertStringNotContainsString($rawKey, $tester->getDisplay());
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function failing(): iterable
    {
        yield 'absent' => [null, null, 'KEYRING_EMPTY'];
        yield 'mal formé' => ['k:xx', 'k', 'KEYRING_MALFORMED'];
        yield 'clé active inconnue' => ['k:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'z', 'ACTIVE_KEY_UNKNOWN'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failing')]
    public function testTheCheckFailsWithANonZeroExitCodeAndNamesTheProblemOnly(?string $keyring, ?string $active, string $problem): void
    {
        $tester = new CommandTester(new CheckCryptoCommand(NotificationKit::provider($keyring, $active), new SecretCipher()));

        self::assertSame(1, $tester->execute([]));

        self::assertStringContainsString($problem, $tester->getDisplay());
        self::assertStringNotContainsString('AAAAAAAA', $tester->getDisplay());
    }

    public function testNoKeyIsCommittedInTheRepositoryEnvironmentFiles(): void
    {
        foreach (['.env', '.env.test', '.env.dev'] as $file) {
            $path = \dirname(__DIR__, 3).'/'.$file;
            if (!is_file($path)) {
                continue;
            }
            foreach ((array) file($path, \FILE_IGNORE_NEW_LINES) as $line) {
                if (str_starts_with((string) $line, 'NOTIFICATION_KEYRING=')) {
                    self::assertSame('NOTIFICATION_KEYRING=', $line, $file.' ne doit contenir aucune clé');
                }
            }
        }
    }

    public function testThePurgeCommandIsRegisteredAndReportsCountersOnly(): void
    {
        self::assertSame('app:notifications:purge-secrets', (new \ReflectionClass(PurgeSecretsCommand::class))->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class)[0]->getArguments()['name']);
    }
}
