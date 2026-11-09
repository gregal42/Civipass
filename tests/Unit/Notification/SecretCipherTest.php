<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Application\SecretCipher;
use App\Notification\Domain\EncryptedSecret;
use App\Notification\Domain\KeyRing;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SecretDecryptionFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Chiffrement authentifié des secrets (LOT 05.3.2, D1, D3) : aller-retour, liaison au message, au type et au laissez-passer, altération, rotation. */
final class SecretCipherTest extends TestCase
{
    private const SECRET = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcde';

    private static function key(): string
    {
        return base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    private static function ring(string ...$ids): KeyRing
    {
        return KeyRing::parse(implode(',', array_map(static fn (string $id): string => $id.':'.self::key(), $ids)), $ids[0]);
    }

    public function testRoundTripThenAnyChangeOfMessageTypeOrPermitIsRejected(): void
    {
        $ring = self::ring('k1');
        [$outbox, $permit] = [Uuid::v7(), Uuid::v7()];
        $cipher = new SecretCipher();
        $encrypted = $cipher->encrypt($ring, self::SECRET, $outbox, NotificationType::PermitIssued, $permit);

        self::assertSame(self::SECRET, $cipher->decrypt($ring, $encrypted, $outbox, NotificationType::PermitIssued, $permit));
        foreach ([[Uuid::v7(), NotificationType::PermitIssued, $permit], [$outbox, NotificationType::AuthorizationAdded, $permit], [$outbox, NotificationType::PermitIssued, Uuid::v7()]] as [$o, $t, $p]) {
            try {
                $cipher->decrypt($ring, $encrypted, $o, $t, $p);
                self::fail('Un secret lié à un autre message, type ou laissez-passer ne doit pas se déchiffrer.');
            } catch (SecretDecryptionFailed $e) {
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function testTheCiphertextIsRandomizedAndNeverContainsThePlaintext(): void
    {
        $ring = self::ring('k1');
        [$o, $p] = [Uuid::v7(), Uuid::v7()];
        $a = (new SecretCipher())->encrypt($ring, self::SECRET, $o, NotificationType::PermitIssued, $p);
        $b = (new SecretCipher())->encrypt($ring, self::SECRET, $o, NotificationType::PermitIssued, $p);

        self::assertNotSame($a->nonce, $b->nonce);
        self::assertNotSame($a->ciphertext, $b->ciphertext);
        self::assertSame(24, \strlen($a->nonce));
        self::assertSame(\strlen(self::SECRET) + 16, \strlen($a->ciphertext), 'secret + étiquette Poly1305 de 16 octets');
        self::assertStringNotContainsString(self::SECRET, $a->ciphertext);
        self::assertSame('k1', $a->keyId);
    }

    #[DataProvider('tamperings')]
    public function testAnyAlterationOfTheStoredDataIsRejected(string $what): void
    {
        $ring = self::ring('k1');
        [$o, $p] = [Uuid::v7(), Uuid::v7()];
        $cipher = new SecretCipher();
        $e = $cipher->encrypt($ring, self::SECRET, $o, NotificationType::PermitIssued, $p);
        $tampered = match ($what) {
            'ciphertext' => new EncryptedSecret($e->keyId, $e->nonce, $e->ciphertext ^ str_repeat("\x00", \strlen($e->ciphertext) - 1)."\x01"),
            'nonce' => new EncryptedSecret($e->keyId, str_repeat("\x00", 24), $e->ciphertext),
            'short nonce' => new EncryptedSecret($e->keyId, 'abc', $e->ciphertext),
            'truncated' => new EncryptedSecret($e->keyId, $e->nonce, substr($e->ciphertext, 0, 10)),
            'unknown key' => new EncryptedSecret('autre', $e->nonce, $e->ciphertext),
            default => throw new \LogicException($what),
        };

        $this->expectException(SecretDecryptionFailed::class);
        $cipher->decrypt($ring, $tampered, $o, NotificationType::PermitIssued, $p);
    }

    /** @return iterable<string, array{string}> */
    public static function tamperings(): iterable
    {
        foreach (['ciphertext', 'nonce', 'short nonce', 'truncated', 'unknown key'] as $what) {
            yield $what => [$what];
        }
    }

    public function testAnotherKeyWithTheSameIdentifierCannotDecrypt(): void
    {
        [$o, $p] = [Uuid::v7(), Uuid::v7()];
        $e = (new SecretCipher())->encrypt(self::ring('k1'), self::SECRET, $o, NotificationType::PermitIssued, $p);

        $this->expectException(SecretDecryptionFailed::class);
        (new SecretCipher())->decrypt(self::ring('k1'), $e, $o, NotificationType::PermitIssued, $p);
    }

    public function testRotationEncryptsWithTheActiveKeyAndStillDecryptsWithTheOldOne(): void
    {
        $old = self::key();
        $new = self::key();
        [$o, $p] = [Uuid::v7(), Uuid::v7()];
        $cipher = new SecretCipher();
        $before = $cipher->encrypt(KeyRing::parse('2025a:'.$old, '2025a'), self::SECRET, $o, NotificationType::PermitIssued, $p);

        $rotated = KeyRing::parse('2026b:'.$new.',2025a:'.$old, '2026b');
        self::assertSame('2025a', $before->keyId);
        self::assertSame(self::SECRET, $cipher->decrypt($rotated, $before, $o, NotificationType::PermitIssued, $p), 'ancien secret lisible pendant la rotation');
        self::assertSame('2026b', $cipher->encrypt($rotated, self::SECRET, $o, NotificationType::PermitIssued, $p)->keyId);

        $this->expectException(SecretDecryptionFailed::class);
        $cipher->decrypt(KeyRing::parse('2026b:'.$new, '2026b'), $before, $o, NotificationType::PermitIssued, $p); // ancienne clé retirée trop tôt
    }

    #[DataProvider('badRings')]
    public function testAMissingOrInvalidKeyRingIsUnusableAndNeverEchoesKeyMaterial(?string $keyring, ?string $active, string $problem): void
    {
        $ring = KeyRing::parse($keyring, $active);

        self::assertFalse($ring->isUsable());
        self::assertSame($problem, $ring->problem());
        self::assertSame([], $ring->ids());
        $this->expectException(\LogicException::class);
        $ring->active();
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function badRings(): iterable
    {
        $good = base64_encode(str_repeat('k', 32));
        yield 'absent' => [null, null, KeyRing::EMPTY];
        yield 'vide' => ['  ', 'k1', KeyRing::EMPTY];
        yield 'sans séparateur' => [$good, 'k1', KeyRing::MALFORMED];
        yield 'base64 invalide' => ['k1:***', 'k1', KeyRing::MALFORMED];
        yield 'clé trop courte' => ['k1:'.base64_encode('court'), 'k1', KeyRing::MALFORMED];
        yield 'identifiant invalide' => ['k 1:'.$good, 'k 1', KeyRing::MALFORMED];
        yield 'identifiant en double' => ['k1:'.$good.',k1:'.$good, 'k1', KeyRing::MALFORMED];
        yield 'clé active absente' => ['k1:'.$good, null, KeyRing::ACTIVE_UNKNOWN];
        yield 'clé active inconnue' => ['k1:'.$good, 'k2', KeyRing::ACTIVE_UNKNOWN];
    }

    public function testNeitherTheKeyRingNorTheEncryptedSecretLeakThroughDebugOutputOrSerialization(): void
    {
        $raw = base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
        $ring = KeyRing::parse('k1:'.$raw, 'k1');
        $encrypted = (new SecretCipher())->encrypt($ring, self::SECRET, Uuid::v7(), NotificationType::PermitIssued, Uuid::v7());

        ob_start();
        var_dump($ring, $encrypted);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString($raw, $dump);
        self::assertStringNotContainsString(bin2hex($encrypted->ciphertext), $dump);
        self::assertStringNotContainsString(base64_encode($encrypted->ciphertext), $dump);
        self::assertStringContainsString('masqué', $dump);
        $this->expectException(\LogicException::class);
        serialize($ring);
    }
}
