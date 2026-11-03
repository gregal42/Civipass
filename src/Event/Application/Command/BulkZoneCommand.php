<?php

declare(strict_types=1);

namespace App\Event\Application\Command;

use App\Event\Application\Result\BulkZoneOperation;

/**
 * Entrée de EventZoneBulkService::apply : une opération sur une sélection de zones d'UNE participation (DM-028).
 * `validity` est obligatoire pour ApplyValidity et interdit sinon ; `confirmOverwrite` autorise le remplacement d'une
 * configuration existante différente (DM-025).
 */
final readonly class BulkZoneCommand
{
    /**
     * @param list<string> $zoneIds
     */
    public function __construct(
        public BulkZoneOperation $operation,
        public array $zoneIds,
        public ?ZoneValidityCommand $validity = null,
        public bool $confirmOverwrite = false,
    ) {
    }
}
