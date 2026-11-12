<?php

declare(strict_types=1);

namespace App\Retention\Application;

/** Rejeu ou réparation refusés (preuve externe absente ou incohérente, approbation manquante, rapport périmé, exécution déjà en cours) : rien n'a été modifié. */
final class ReplayRefused extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
