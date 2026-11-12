<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Shared\Domain\DomainRuleViolation;

/** L'exécution d'un effacement anticipé est désactivée (arbitrage D1) : la décision peut être instruite et enregistrée, jamais exécutée. */
final class ErasureExecutionDisabled extends DomainRuleViolation
{
    public static function create(): self
    {
        return new self('L’exécution des effacements anticipés est désactivée tant que la politique juridique applicable n’est pas validée : la décision est enregistrée, aucune donnée n’est supprimée.');
    }
}
