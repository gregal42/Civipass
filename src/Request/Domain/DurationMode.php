<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Mode de durée d'une approbation (DM-109). Les durées fixes sont en heures absolues UTC, jamais en jours calendaires. */
enum DurationMode: string
{
    case H24 = 'H24';
    case H48 = 'H48';
    case H72 = 'H72';
    case Custom = 'CUSTOM';
    case UntilClosure = 'UNTIL_CLOSURE';

    /** Nombre d'heures d'un mode fixe, nul pour les autres. */
    public function fixedHours(): ?int
    {
        return match ($this) {
            self::H24 => 24,
            self::H48 => 48,
            self::H72 => 72,
            self::Custom, self::UntilClosure => null,
        };
    }
}
