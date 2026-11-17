<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limitation par fenêtre glissante (`verification_access`, `verification_failure`), séparée des limiteurs du lien bénéficiaire (DT-055). Le plafond
 * d'échecs n'est plus consulté avant la recherche : il est consommé APRÈS elle, pour un jeton inconnu seulement, de sorte qu'une série de jetons
 * inconnus ne peut jamais bloquer un jeton valide partageant la même adresse. Toute panne du stockage devient une ThrottleStorageUnavailable :
 * jamais de limitation silencieusement perdue.
 *
 * Journal des seuils : une ligne de niveau `error` (le journal par défaut de Symfony n'écrit que les erreurs : un niveau inférieur serait invisible en exploitation) au message fixe, SANS adresse, clé, jeton ni donnée, lorsqu'un plafond vient d'être atteint (dernière consommation
 * admise). Un seul message par fenêtre et par client, donc volumétrie bornée : il sert à détecter l'inondation ou l'énumération en exploitation.
 */
#[AsAlias(VerificationThrottle::class)]
final readonly class RateLimiterVerificationThrottle implements VerificationThrottle
{
    private const MAX_WAIT = 900;

    public function __construct(
        #[Autowire(service: 'limiter.verification_access')] private RateLimiterFactoryInterface $access,
        #[Autowire(service: 'limiter.verification_failure')] private RateLimiterFactoryInterface $failures,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function check(string $clientKey): ?int
    {
        try {
            $access = $this->access->create($clientKey)->consume();
        } catch (\Throwable $e) {
            throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.', 0, $e);
        }
        if (!$access->isAccepted()) {
            return self::wait($access->getRetryAfter());
        }
        if ($access->getRemainingTokens() < 1) {
            $this->logger->error('Contrôle public : plafond de consultations atteint pour une adresse, les suivantes seront refusées (429).');
        }

        return null;
    }

    public function recordFailure(string $clientKey): ?int
    {
        try {
            $failure = $this->failures->create($clientKey)->consume();
        } catch (\Throwable $e) {
            throw new ThrottleStorageUnavailable('Compteurs de limitation indisponibles.', 0, $e);
        }
        if (!$failure->isAccepted()) {
            return self::wait($failure->getRetryAfter());
        }
        if ($failure->getRemainingTokens() < 1) {
            $this->logger->error('Contrôle public : plafond de jetons inconnus atteint pour une adresse, les jetons inconnus suivants seront refusés (429) ; les jetons valides ne sont pas touchés.');
        }

        return null;
    }

    private static function wait(\DateTimeImmutable $retryAfter): int
    {
        return max(1, min(self::MAX_WAIT, $retryAfter->getTimestamp() - time()));
    }
}
