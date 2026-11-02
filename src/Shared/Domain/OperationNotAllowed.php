<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Une opération valide en soi est refusée (droit insuffisant, protection du dernier administrateur…).
 */
final class OperationNotAllowed extends DomainRuleViolation
{
}
