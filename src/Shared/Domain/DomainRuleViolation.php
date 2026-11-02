<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Une donnée ou un état viole une règle métier (valeur invalide, invariant).
 */
class DomainRuleViolation extends \DomainException
{
}
