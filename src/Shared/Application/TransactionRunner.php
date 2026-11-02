<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface TransactionRunner
{
    /**
     * Exécute $operation dans une transaction, flush et commit.
     *
     * - Refus métier (DomainRuleViolation) : rollback, l'EntityManager reste utilisable
     *   tant que l'opération n'a laissé aucune modification en attente.
     * - Toute autre exception : rollback et EntityManager fermé (état potentiellement incohérent).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
