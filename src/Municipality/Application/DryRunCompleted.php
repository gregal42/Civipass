<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Interne : interrompt la transaction d'un essai à blanc pour l'annuler (rollback), en gardant l'EntityManager utilisable
 * (un refus métier ne le ferme pas).
 */
final class DryRunCompleted extends DomainRuleViolation
{
    public function __construct(public readonly SynchronizationReport $report)
    {
        parent::__construct('Essai à blanc terminé : aucune écriture.');
    }
}
