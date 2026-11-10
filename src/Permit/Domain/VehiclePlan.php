<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Résultat de la règle des plaques (LOT 05.5.4) : les changements dans l'ordre d'écriture, le nombre de plaques actives APRÈS l'opération et la présence d'un
 * retrait (retrait ou remplacement : notification prioritaire).
 */
final readonly class VehiclePlan
{
    /** @param list<VehicleChange> $changes */
    public function __construct(public array $changes, public int $activeAfter)
    {
    }

    public function hasRemoval(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->kind->removesPlate()) {
                return true;
            }
        }

        return false;
    }
}
