<?php

declare(strict_types=1);

namespace App\Rights\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Paramètres des droits des personnes (LOT 05.6.6) : HYPOTHÈSES à confirmer juridiquement, jamais des constantes dans la logique. */
final readonly class RightsSettings
{
    public function __construct(
        #[Autowire('%env(int:RIGHTS_RESPONSE_DAYS)%')] private int $responseDays,
        #[Autowire('%env(int:RIGHTS_EXTENSION_DAYS)%')] private int $extensionDays,
        #[Autowire('%env(int:RIGHTS_EXPORT_WINDOW_DAYS)%')] private int $exportWindowDays,
        #[Autowire('%env(int:RIGHTS_EXPORT_MAX_DOWNLOADS)%')] private int $exportMaxDownloads,
        #[Autowire('%env(bool:RIGHTS_ERASURE_EXECUTION_ENABLED)%')] private bool $erasureExecutionEnabled,
    ) {
    }

    public function responseDays(): int
    {
        return max(1, $this->responseDays);
    }

    public function extensionDays(): int
    {
        return max(1, $this->extensionDays);
    }

    /** Fenêtre d'accès à l'export : 30 jours au plus (arbitrage D3). */
    public function exportWindowDays(): int
    {
        return max(1, min(30, $this->exportWindowDays));
    }

    /** Téléchargements : 3 au plus (arbitrage D3). */
    public function exportMaxDownloads(): int
    {
        return max(1, min(3, $this->exportMaxDownloads));
    }

    /** Interrupteur de sécurité : désactivé par défaut (arbitrage D1). */
    public function erasureExecutionEnabled(): bool
    {
        return $this->erasureExecutionEnabled;
    }
}
