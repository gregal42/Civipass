<?php

declare(strict_types=1);

namespace App\Ops\Application;

use Psr\Clock\ClockInterface;

/** État des tâches d'exploitation : dernier succès connu, trop ancien ou jamais exécuté (LOT 05.6.7). Lecture seule. */
final class OpsStatus
{
    public const OK = 'OK';
    public const STALE = 'STALE';
    public const NEVER = 'NEVER_RAN';

    public function __construct(private readonly HeartbeatStore $heartbeats, private readonly ClockInterface $clock, private readonly MaintenanceMode $maintenance)
    {
    }

    /**
     * @param list<string>|null $jobs sous-ensemble ; toutes les tâches de mise en service par défaut
     *
     * @return array{maintenance: bool, maintenanceForgotten: bool, jobs: array<string, array{state: string, ageSeconds: int|null}>}
     */
    public function report(?array $jobs = null): array
    {
        $now = $this->clock->now();
        $lines = [];
        foreach (JobRegistry::GO_LIVE as $job => $maxAge) {
            if (null !== $jobs && !\in_array($job, $jobs, true)) {
                continue;
            }
            $last = $this->heartbeats->last($job);
            if (null === $last) {
                $lines[$job] = ['state' => self::NEVER, 'ageSeconds' => null];
                continue;
            }
            $age = max(0, $now->getTimestamp() - $last->getTimestamp());
            $lines[$job] = ['state' => $age > $maxAge ? self::STALE : self::OK, 'ageSeconds' => $age];
        }

        $since = $this->maintenance->since();

        // Une maintenance active est ATTENDUE (les tâches sont suspendues) ; oubliée au-delà de 24 h, elle devient une anomalie.
        return ['maintenance' => $this->maintenance->active(), 'maintenanceForgotten' => $this->maintenance->active() && (null === $since || $since < $now->modify('-24 hours')), 'jobs' => $lines];
    }
}
