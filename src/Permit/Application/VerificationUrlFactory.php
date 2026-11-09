<?php

declare(strict_types=1);

namespace App\Permit\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * URL publique de vérification portée par le QR : `{domaine public configuré}/v/{jeton}`. Le domaine vient UNIQUEMENT de la configuration
 * (`DEFAULT_URI`), jamais d'un en-tête Host ou X-Forwarded-* fourni par le client : une requête forgée ne peut pas faire imprimer un QR qui
 * pointe ailleurs. Le jeton est celui du LOT 05.1, inchangé ; la page publique elle-même relève du LOT 05.4.
 */
final readonly class VerificationUrlFactory
{
    public function __construct(#[Autowire('%env(DEFAULT_URI)%')] private string $publicBaseUrl)
    {
    }

    /** URL absolue d'un chemin applicatif (ex. lien bénéficiaire) sur le domaine configuré : jamais celui de la requête. */
    public function absolute(string $path): string
    {
        return rtrim($this->publicBaseUrl, '/').'/'.ltrim($path, '/');
    }

    public function forToken(string $verificationToken): string
    {
        return rtrim($this->publicBaseUrl, '/').'/v/'.$verificationToken;
    }
}
