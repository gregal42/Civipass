<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

/**
 * Configuration complète de la validité d'une zone (remplacement). `mode` : EVENT_DURATION (aucun créneau) ou
 * SPECIFIC_PERIODS (au moins un créneau).
 *
 * `expectedRevision` (facultatif) : empreinte de la configuration que l'appelant a vue (ValidityRevision). Si elle ne correspond plus
 * à la configuration lue SOUS les verrous de la zone, rien n'est écrit et le service lève OperationConflict (StaleState) : aucune
 * fusion, aucune nouvelle tentative. Absente : comportement inchangé (remplacement sans condition).
 */
final readonly class ZoneValidityCommand
{
    /**
     * @param list<ValiditySlotInput> $slots
     */
    public function __construct(
        public string $mode,
        public array $slots = [],
        public ?string $expectedRevision = null,
    ) {
    }
}
