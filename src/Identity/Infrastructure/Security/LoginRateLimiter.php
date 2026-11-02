<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;

/**
 * Limitation de la connexion : 5 tentatives / 15 min par couple identifiant+IP et plafond global par IP
 * (seuils dans framework.yaml). Délègue à DefaultLoginRateLimiter : seul le compteur identifiant+IP est remis à
 * zéro après une connexion réussie, le plafond par IP survit (il borne le nombre d'identifiants essayés).
 */
final class LoginRateLimiter implements RequestRateLimiterInterface
{
    private readonly DefaultLoginRateLimiter $inner;

    public function __construct(
        #[Autowire(service: 'limiter.login_global')] RateLimiterFactory $global,
        #[Autowire(service: 'limiter.login_local')] RateLimiterFactory $local,
        #[Autowire('%kernel.secret%')] string $secret,
    ) {
        $this->inner = new DefaultLoginRateLimiter($global, $local, $secret);
    }

    public function consume(Request $request): RateLimit
    {
        return $this->inner->consume($request);
    }

    public function reset(Request $request): void
    {
        $this->inner->reset($request);
    }
}
