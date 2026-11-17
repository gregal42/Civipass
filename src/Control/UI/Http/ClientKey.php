<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Clé de limitation d'une requête de l'API de contrôle : l'adresse du client telle que la voit Symfony (en-têtes de provenance crus seulement d'un proxy déclaré de
 * confiance). IPv4 : adresse complète ; IPv6 : préfixe /64 ; adresse illisible : clé commune `unresolved` (échec fermé). Même règle que le contrôle public (DT-055).
 */
final class ClientKey
{
    public static function of(Request $request): string
    {
        $address = trim((string) ($request->getClientIp() ?? $request->server->get('REMOTE_ADDR', '')), "[] \t\n\r\0\x0B");
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
