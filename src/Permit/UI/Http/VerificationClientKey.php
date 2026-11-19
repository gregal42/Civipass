<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Shared\UI\Http\ClientNetworkKey;
use Symfony\Component\HttpFoundation\Request;

/**
 * Clé de limitation du contrôle public (LOT 05.4.3). La règle (adresse vue par Symfony, IPv6 en /64, IPv4-mappée, adresse illisible) est portée par
 * `ClientNetworkKey`, partagée avec le dépôt public (SEC-003) ; cette classe en conserve l'interface historique.
 */
final class VerificationClientKey
{
    public static function of(Request $request): string
    {
        return ClientNetworkKey::of($request);
    }

    public static function fromAddress(string $address): string
    {
        return ClientNetworkKey::fromAddress($address);
    }
}
