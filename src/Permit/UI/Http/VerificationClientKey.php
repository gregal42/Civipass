<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Clé de limitation du contrôle public (LOT 05.4.3) : l'adresse du client telle que la voit Symfony. `getClientIp()` ne croit un en-tête de
 * provenance (`X-Forwarded-For`…) QUE d'un proxy déclaré de confiance : sans proxy déclaré, `X-Forwarded-For`, `Forwarded`, `X-Real-IP`,
 * `True-Client-IP` et `CF-Connecting-IP` d'un client sont ignorés. IPv4 : adresse complète. IPv6 : préfixe /64 (un abonné en possède 2^64 adresses ;
 * une clé par adresse serait contournable et remplirait le stockage). Adresse IPv4-mappée (`::ffff:a.b.c.d`) : traitée comme l'IPv4. Adresse
 * illisible : clé commune `unresolved` (échec fermé : plus strict, jamais contournable).
 */
final class VerificationClientKey
{
    public static function of(Request $request): string
    {
        return self::fromAddress($request->getClientIp() ?? (string) $request->server->get('REMOTE_ADDR', ''));
    }

    public static function fromAddress(string $address): string
    {
        $address = trim($address, "[] \t\n\r\0\x0B");
        if (false !== ($zone = strpos($address, '%'))) {
            $address = substr($address, 0, $zone);
        }
        $packed = '' === $address ? false : @inet_pton($address);
        if (false === $packed) {
            return 'unresolved';
        }
        if (4 === \strlen($packed)) {
            return 'v4:'.(string) inet_ntop($packed);
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return 'v4:'.(string) inet_ntop(substr($packed, 12));
        }

        return 'v6:'.(string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
