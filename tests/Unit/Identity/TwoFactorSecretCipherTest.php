<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\TwoFactorSecretCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Chiffrement du secret TOTP au repos (SEC-006) : jamais en clair, lié au compte, non transférable, infalsifiable.
 */
final class TwoFactorSecretCipherTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testTheStoredValueIsNotThePlaintextAndDecryptsBack(): void
    {
        $cipher = new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789');
        $user = Uuid::v7();

        $stored = $cipher->encrypt(self::SECRET, $user);

        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString(self::SECRET, (string) base64_decode($stored, true));
        self::assertNotSame($stored, $cipher->encrypt(self::SECRET, $user), 'nonce aléatoire : deux chiffrements diffèrent');
        self::assertSame(self::SECRET, $cipher->decrypt($stored, $user));
    }

    public function testASecretCopiedToAnotherAccountOrAlteredOrUnderAnotherKeyIsUnreadable(): void
    {
        $cipher = new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789');
        $stored = $cipher->encrypt(self::SECRET, $user = Uuid::v7());

        foreach ([
            'autre compte' => static fn (): string => $cipher->decrypt($stored, Uuid::v7()),
            'valeur altérée' => static fn (): string => $cipher->decrypt(base64_encode(substr((string) base64_decode($stored, true), 0, -1).'x'), $user),
            'autre secret applicatif' => static fn (): string => (new TwoFactorSecretCipher('un-autre-secret-applicatif-0123456789'))->decrypt($stored, $user),
            'valeur absurde' => static fn (): string => $cipher->decrypt('%%%', $user),
        ] as $label => $attempt) {
            try {
                $attempt();
                self::fail($label.' : le secret ne devrait pas se déchiffrer');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage(), $label);
            }
        }
    }

    public function testBackupCodeHashesAreStableKeyedAndNotTheCode(): void
    {
        $cipher = new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789');
        $other = new TwoFactorSecretCipher('un-autre-secret-applicatif-0123456789');

        self::assertSame($cipher->backupCodeHash('abcde23456'), $cipher->backupCodeHash('abcde23456'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cipher->backupCodeHash('abcde23456'));
        self::assertNotSame($cipher->backupCodeHash('abcde23456'), $other->backupCodeHash('abcde23456'), 'empreinte liée au secret applicatif');
        self::assertNotSame($cipher->backupCodeHash('abcde23456'), hash('sha256', 'abcde23456'), 'pas un simple SHA-256 du code');
    }

    private static function key(string $seed): string
    {
        return base64_encode(hash('sha256', $seed, true));
    }

    public function testADedicatedKeyringEncryptsAndKeepsHistoricalSecretsReadable(): void
    {
        $user = Uuid::v7();
        $legacy = (new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789'))->encrypt(self::SECRET, $user);
        $dedicated = new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789', 'k2026:'.self::key('a'), 'k2026');

        self::assertTrue($dedicated->usesDedicatedKey());
        $stored = $dedicated->encrypt(self::SECRET, $user);
        self::assertStringStartsWith('k1.k2026.', $stored);
        self::assertSame(self::SECRET, $dedicated->decrypt($stored, $user));
        self::assertSame(self::SECRET, $dedicated->decrypt($legacy, $user), 'passer au trousseau dédié ne force aucune réinscription');
    }

    public function testRotationAddsANewActiveKeyWhileTheOldOneStillDecrypts(): void
    {
        $user = Uuid::v7();
        $app = 'un-secret-applicatif-de-test-0123456789';
        $old = (new TwoFactorSecretCipher($app, 'k1:'.self::key('a'), 'k1'))->encrypt(self::SECRET, $user);
        $rotated = new TwoFactorSecretCipher($app, 'k2:'.self::key('b').',k1:'.self::key('a'), 'k2');

        self::assertSame(self::SECRET, $rotated->decrypt($old, $user));
        self::assertStringStartsWith('k1.k2.', $rotated->encrypt(self::SECRET, $user));

        $withoutOld = new TwoFactorSecretCipher($app, 'k2:'.self::key('b'), 'k2');
        $this->expectException(\RuntimeException::class);
        $withoutOld->decrypt($old, $user);
    }

    public function testBackupCodeHashesSurviveKeyRotation(): void
    {
        $app = 'un-secret-applicatif-de-test-0123456789';
        $one = new TwoFactorSecretCipher($app, 'k1:'.self::key('a'), 'k1');
        $two = new TwoFactorSecretCipher($app, 'k2:'.self::key('b'), 'k2');

        self::assertSame($one->backupCodeHash('ABCDE-FGHJK'), $two->backupCodeHash('ABCDE-FGHJK'));
        self::assertSame((new TwoFactorSecretCipher($app))->backupCodeHash('ABCDE-FGHJK'), $one->backupCodeHash('ABCDE-FGHJK'));
    }

    public function testAMalformedKeyringFallsBackToTheHistoricalKeyNeverToAnEmptyKey(): void
    {
        $cipher = new TwoFactorSecretCipher('un-secret-applicatif-de-test-0123456789', 'pas-un-trousseau', 'k1');

        self::assertFalse($cipher->usesDedicatedKey());
        $user = Uuid::v7();
        self::assertSame(self::SECRET, $cipher->decrypt($cipher->encrypt(self::SECRET, $user), $user));
    }
}
