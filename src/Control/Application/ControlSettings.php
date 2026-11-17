<?php

declare(strict_types=1);

namespace App\Control\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paramètres du contrôle hors connexion (LOT 09, DM-129 à DM-132). Valeurs par défaut du PILOTE : avertissement 6 h, seuil dur 12 h.
 *
 * - Plafond TECHNIQUE absolu du seuil dur : 24 h (constante, CDC §9.2). Plafond de CONFIGURATION : `CONTROL_TRUST_HARD_MAX_SECONDS` (12 h pour le pilote) ;
 *   le relever est un changement de configuration soumis à une décision explicite (DM-130 §4). La valeur d'environnement ne peut jamais dépasser
 *   le plafond technique, quoi qu'elle contienne.
 * - Une commune peut RACCOURCIR son seuil dur (bornes : 2 h au moins) ; avertissement ≤ seuil dur.
 */
final readonly class ControlSettings
{
    public const TECHNICAL_HARD_CAP_SECONDS = 86_400;
    public const MIN_HARD_SECONDS = 7_200;
    public const MAX_DEVICES_PER_CODE = 10;
    public const MAX_ACTIVE_CODES = 3;
    public const MAX_PENDING_DEVICES = 20;
    public const MIN_CODE_MINUTES = 10;
    public const MAX_CODE_MINUTES = 60;

    public bool $enabled;
    public int $hardMaxSeconds;
    public int $defaultWarnSeconds;
    public int $codeMinutes;
    public int $pendingMinutes;
    public int $deviceLifetimeHours;

    public function __construct(
        #[Autowire('%env(bool:OFFLINE_CONTROL_ENABLED)%')] bool $enabled,
        #[Autowire('%env(int:CONTROL_TRUST_HARD_MAX_SECONDS)%')] int $hardMaxSeconds,
        #[Autowire('%env(int:CONTROL_TRUST_WARN_SECONDS)%')] int $warnSeconds,
        #[Autowire('%env(int:CONTROL_CODE_MINUTES)%')] int $codeMinutes,
        #[Autowire('%env(int:CONTROL_PENDING_MINUTES)%')] int $pendingMinutes,
        #[Autowire('%env(int:CONTROL_DEVICE_LIFETIME_HOURS)%')] int $deviceLifetimeHours,
    ) {
        $this->enabled = $enabled;
        $this->hardMaxSeconds = max(self::MIN_HARD_SECONDS, min(self::TECHNICAL_HARD_CAP_SECONDS, $hardMaxSeconds));
        $this->defaultWarnSeconds = max(1_800, min($this->hardMaxSeconds, $warnSeconds));
        $this->codeMinutes = max(self::MIN_CODE_MINUTES, min(self::MAX_CODE_MINUTES, $codeMinutes));
        $this->pendingMinutes = max(5, min(240, $pendingMinutes));
        $this->deviceLifetimeHours = max(1, min(24 * 14, $deviceLifetimeHours));
    }

    /** Seuil dur par défaut = le plafond de configuration (12 h au pilote). */
    public function defaultHardSeconds(): int
    {
        return $this->hardMaxSeconds;
    }

    /**
     * Seuils demandés par une commune, ramenés dans les bornes : seuil dur au plus le plafond de configuration (une commune ne peut que RACCOURCIR),
     * avertissement au plus le seuil dur.
     *
     * @return array{warn: int, hard: int}
     */
    public function clampThresholds(?int $warn, ?int $hard): array
    {
        $hardValue = max(self::MIN_HARD_SECONDS, min($this->hardMaxSeconds, $hard ?? $this->hardMaxSeconds));
        $warnValue = max(1_800, min($hardValue, $warn ?? min($this->defaultWarnSeconds, $hardValue)));

        return ['warn' => $warnValue, 'hard' => $hardValue];
    }
}
