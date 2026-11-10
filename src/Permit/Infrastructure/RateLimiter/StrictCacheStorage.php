<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure\RateLimiter;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Stockage des compteurs de limitation du contrôle public (LOT 05.4.3). Même rôle que `CacheStorage` du framework, avec UNE différence voulue : une
 * écriture qui échoue lève une exception. Le stockage standard ignore le résultat de `save()` ; sur un disque plein ou en lecture seule il ne
 * mémoriserait plus rien et la limitation cesserait, sans aucun signal. Ici la panne est visible : le contrôleur répond 503 et la journalise.
 */
final readonly class StrictCacheStorage implements StorageInterface
{
    public function __construct(#[Autowire(service: 'cache.rate_limiter')] private CacheItemPoolInterface $pool)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $item = $this->pool->getItem(sha1($limiterState->getId()));
        $item->set($limiterState);
        if (null !== ($expireAfter = $limiterState->getExpirationTime())) {
            $item->expiresAfter($expireAfter);
        }
        if (!$this->pool->save($item)) {
            throw new RateLimiterStorageFailure('Écriture du compteur de limitation impossible.');
        }
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $value = $this->pool->getItem(sha1($limiterStateId))->get();

        return $value instanceof LimiterStateInterface ? $value : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->pool->deleteItem(sha1($limiterStateId));
    }
}
