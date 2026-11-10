<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limitation par fenêtre glissante (`verification_access`, `verification_failure`), séparée des limiteurs du lien bénéficiaire. Le plafond d'échecs
 * est CONSULTÉ sans être consommé (`consume(0)`) à chaque accès : atteint, plus aucune recherche n'a lieu depuis cette adresse, même pour un jeton
 * valide. Toute panne du stockage devient une ThrottleStorageUnavailable : jamais de limitation silencieusement perdue.
 */
#[AsAlias(VerificationThrottle::class)]
final readonly class RateLimiterVerificationThrottle implements VerificationThrottle
{
    private const MAX_WAIT = 900;

    public function __construct(
        #[Autowire(service: 'limiter.verification_access')] private RateLimiterFactoryInterface $access,
        #[Autowire(service: 'limiter.verification_failure')] private RateLimiterFactoryInterface $failures,
    ) {
    }

    public function check(string $clientKey): ?int
    {
        try {
            $access = $this->access->create($clientKey)->consume();
            if (!$access->isAccepted()) {
                return self::wait($access->getRetryAfter());
            }
            $failures = $this->failures->create($clientKey)->consume(0);

            return $failures->getRemainingTokens() < 1 ? self::wait($failures->getRetryAfter()) : null;
        } catch (\Throwable $e) {
            throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.', 0, $e);
        }
    }

    public function recordFailure(string $clientKey): void
    {
        try {
            $this->failures->create($clientKey)->consume();
        } catch (\Throwable $e) {
            throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.', 0, $e);
        }
    }

    private static function wait(\DateTimeImmutable $retryAfter): int
    {
        return max(1, min(self::MAX_WAIT, $retryAfter->getTimestamp() - time()));
    }
}
