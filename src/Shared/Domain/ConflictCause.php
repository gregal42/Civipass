<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum ConflictCause: string
{
    /** `innodb_lock_wait_timeout` dépassé (1205). */
    case LockWaitTimeout = 'LOCK_WAIT_TIMEOUT';
    /** Interblocage détecté par le serveur (1213). */
    case Deadlock = 'DEADLOCK';
    /** Unicité violée par une écriture concurrente, sur une contrainte explicitement déclarée par le service. */
    case UniqueViolation = 'UNIQUE_VIOLATION';
}
