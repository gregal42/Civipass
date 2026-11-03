<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

/**
 * Résultat d'une opération groupée : zones demandées et zones effectivement modifiées ; `batchId` vaut null lorsque rien n'a
 * changé (aucun audit).
 */
final readonly class BulkZoneResult
{
    /**
     * @param list<string> $requestedZoneIds
     * @param list<string> $modifiedZoneIds
     */
    public function __construct(
        public BulkZoneOperation $operation,
        public string $participationId,
        public array $requestedZoneIds,
        public array $modifiedZoneIds,
        public bool $changed,
        public ?string $batchId,
    ) {
    }
}
