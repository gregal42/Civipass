<?php

declare(strict_types=1);

namespace App\Retention\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paramètres de rétention (LOT 05.6.4) : HYPOTHÈSES à valider avec le DPO et le service d'archives, jamais des constantes. `purgeEnabled` est un INTERRUPTEUR de
 * sécurité : désactivé par défaut, la purge réelle (`--execute`) est REFUSÉE ; il n'est jamais activé en production sans accord explicite et validation de la politique.
 */
final readonly class RetentionSettings
{
    public function __construct(
        #[Autowire('%env(int:RETENTION_NOTICE_DAYS)%')] private int $noticeDays,
        #[Autowire('%env(bool:RETENTION_PURGE_ENABLED)%')] private bool $purgeEnabled,
        #[Autowire('%env(RETENTION_REGISTER_PATH)%')] private string $registerPath,
        #[Autowire('%env(int:CLOSURE_STATISTICS_MIN_COUNT)%')] private int $statisticsThreshold = 5,
    ) {
    }

    /** Plancher de sécurité : au moins un jour de préavis. */
    public function noticeDays(): int
    {
        return max(1, $this->noticeDays);
    }

    public function purgeEnabled(): bool
    {
        return $this->purgeEnabled;
    }

    public function registerPath(): string
    {
        return $this->registerPath;
    }

    public function statisticsThreshold(): int
    {
        return $this->statisticsThreshold;
    }
}
