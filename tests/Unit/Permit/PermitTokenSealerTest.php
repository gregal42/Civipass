<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\VerificationToken;
use App\Permit\Infrastructure\Security\PermitTokenSealer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** SEC-020 : copie scellée du jeton de contrôle : non en clair, liée à sa ligne, infalsifiable, rotation sans invalider aucun QR. */
final class PermitTokenSealerTest extends TestCase
{
    private const APP = 'un-secret-applicatif-de-test-0123456789';

    private static function key(string $seed): string
    {
        return base64_encode(hash('sha256', $seed, true));
    }

    public function testTheSealedValueHidesTheTokenAndOpensBackOnlyForItsOwnRow(): void
    {
        $sealer = new PermitTokenSealer(self::APP);
        $token = VerificationToken::generate();
        $row = Uuid::v7();

        $sealed = $sealer->seal($token, $row);

        self::assertStringNotContainsString($token, $sealed);
        self::assertStringNotContainsString($token, (string) base64_decode(explode('.', $sealed, 3)[2], true));
        self::assertNotSame($sealed, $sealer->seal($token, $row), 'nonce aléatoire');
        self::assertSame($token, $sealer->open($sealed, $row));
        $this->expectException(\RuntimeException::class);
        $sealer->open($sealed, Uuid::v7());
    }

    public function testAnAlteredOrForeignValueIsRefusedWithoutLeakingAnything(): void
    {
        $sealer = new PermitTokenSealer(self::APP, 't1:'.self::key('a'), 't1');
        $row = Uuid::v7();
        $sealed = $sealer->seal('abcdefghijklmnopqrstuvwxyz234567', $row);
        foreach ([substr($sealed, 0, -2).'xx', 'k1.t9.'.explode('.', $sealed, 3)[2], 'n-importe-quoi', ''] as $bad) {
            try {
                $sealer->open($bad, $row);
                self::fail('valeur illisible acceptée');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('abcdefghijklmnop', $e->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        (new PermitTokenSealer(self::APP, 't1:'.self::key('autre'), 't1'))->open($sealed, $row);
    }

    public function testRotationKeepsEveryExistingSealedTokenReadableAndFallbackStaysReadable(): void
    {
        $row = Uuid::v7();
        $token = VerificationToken::generate();
        $legacy = (new PermitTokenSealer(self::APP))->seal($token, $row);
        $one = (new PermitTokenSealer(self::APP, 't1:'.self::key('a'), 't1'))->seal($token, $row);
        $rotated = new PermitTokenSealer(self::APP, 't2:'.self::key('b').',t1:'.self::key('a'), 't2');

        self::assertSame('t2', $rotated->activeKeyId());
        self::assertSame('t1', $rotated->keyIdOf($one));
        self::assertSame($token, $rotated->open($one, $row), 'aucun QR invalidé par la rotation');
        self::assertSame($token, $rotated->open($legacy, $row), 'passer à une clé dédiée ne casse pas les copies historiques');
        self::assertStringStartsWith('k1.t2.', $rotated->seal($token, $row));
    }

    public function testFingerprintIsTheOfflineDatasetKeyAndIsCaseInsensitive(): void
    {
        $token = VerificationToken::generate();

        self::assertSame(hash('sha256', $token), VerificationToken::fingerprint($token));
        self::assertSame(VerificationToken::fingerprint($token), VerificationToken::fingerprint(strtoupper($token)));
    }
}
