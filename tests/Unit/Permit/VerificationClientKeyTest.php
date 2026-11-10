<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\UI\Http\VerificationClientKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Clé de limitation du contrôle public : IPv4 entière, IPv6 par /64, IPv4-mappée traitée comme l'IPv4, en-têtes de provenance d'un client ignorés.
 */
final class VerificationClientKeyTest extends TestCase
{
    protected function setUp(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['203.0.113.7', 'v4:203.0.113.7'];
        yield 'IPv4 voisine : autre clé' => ['203.0.113.8', 'v4:203.0.113.8'];
        yield 'IPv6' => ['2001:db8:1:2::1', 'v6:2001:db8:1:2::/64'];
        yield 'IPv6 du même /64' => ['2001:db8:1:2:ffff:ffff:ffff:ffff', 'v6:2001:db8:1:2::/64'];
        yield 'IPv6 écrite en long' => ['2001:0db8:0001:0002:0000:0000:0000:0001', 'v6:2001:db8:1:2::/64'];
        yield 'IPv6 d\'un autre /64' => ['2001:db8:1:3::1', 'v6:2001:db8:1:3::/64'];
        yield 'IPv6 entre crochets' => ['[2001:db8:1:2::9]', 'v6:2001:db8:1:2::/64'];
        yield 'IPv6 avec zone' => ['fe80::1%eth0', 'v6:fe80::/64'];
        yield 'IPv4-mappée' => ['::ffff:203.0.113.7', 'v4:203.0.113.7'];
        yield 'IPv4-mappée en hexadécimal' => ['::ffff:cb00:7107', 'v4:203.0.113.7'];
        yield 'illisible' => ['pas-une-ip', 'unresolved'];
        yield 'vide' => ['', 'unresolved'];
        yield 'injection' => ["203.0.113.7\n' OR 1=1", 'unresolved'];
    }

    #[DataProvider('addresses')]
    public function testTheKeyIsTheIpv4OrTheIpv6Prefix(string $address, string $expected): void
    {
        self::assertSame($expected, VerificationClientKey::fromAddress($address));
    }

    public function testAMappedAddressSharesTheKeyOfItsIpv4(): void
    {
        self::assertSame(VerificationClientKey::fromAddress('203.0.113.7'), VerificationClientKey::fromAddress('::ffff:203.0.113.7'));
    }

    public function testForgedProvenanceHeadersAreIgnoredWithoutATrustedProxy(): void
    {
        $request = Request::create('/v/x', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2',
            'HTTP_FORWARDED' => 'for=10.9.9.9',
            'HTTP_X_REAL_IP' => '10.8.8.8',
            'HTTP_TRUE_CLIENT_IP' => '10.7.7.7',
            'HTTP_CF_CONNECTING_IP' => '10.6.6.6',
        ]);

        self::assertSame('v4:198.51.100.9', VerificationClientKey::of($request));
    }

    public function testAnExplicitlyTrustedProxyIsTheOnlyOneWhoseHeaderIsBelieved(): void
    {
        Request::setTrustedProxies(['192.0.2.1'], Request::HEADER_X_FORWARDED_FOR);
        try {
            $fromProxy = Request::create('/v/x', 'GET', server: ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.50']);
            $fromClient = Request::create('/v/x', 'GET', server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.50']);

            self::assertSame('v4:203.0.113.50', VerificationClientKey::of($fromProxy));
            self::assertSame('v4:198.51.100.9', VerificationClientKey::of($fromClient), 'un client qui n\'est pas le proxy ne peut pas choisir son adresse');
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function testAMissingAddressFallsBackToTheSharedStrictKey(): void
    {
        $request = Request::create('/v/x');
        $request->server->remove('REMOTE_ADDR');

        self::assertSame('unresolved', VerificationClientKey::of($request));
    }
}
