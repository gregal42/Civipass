<?php

declare(strict_types=1);

namespace App\Retention\Application;

/**
 * Point d'observation APRÈS chaque étape d'une purge (dans la transaction) : sans effet en production ; les tests y injectent une panne pour prouver la reprise et le
 * retour arrière à CHAQUE étape.
 */
interface PurgeStepObserver
{
    public function afterStep(string $step): void;
}
