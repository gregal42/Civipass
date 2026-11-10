<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/**
 * Plaques du laissez-passer et actions possibles pour l'agent d'une commune (LOT 05.5.4, indicatif : tout est relu sous verrou à l'écriture). `vehicleMode` faux (pied/vélo) :
 * aucune plaque ni action. `otherMunicipalities` : les AUTRES communes titulaires d'une autorisation non révoquée, annoncées avant confirmation de la portée globale.
 */
final readonly class VehiclesPanel
{
    /**
     * @param list<VehicleRowView> $rows                actives puis retirées
     * @param list<string>         $otherMunicipalities
     */
    public function __construct(
        public bool $vehicleMode,
        public array $rows,
        public int $activeCount,
        public int $max,
        public bool $canChange,
        public string $blockedReason,
        public bool $canAdd,
        public string $addBlockedReason,
        public array $otherMunicipalities,
    ) {
    }
}
