<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

use App\Event\Domain\Validity\ValidityMode;

/** Résultat de la configuration de validité : créneaux créés et supprimés logiquement ; `changed` = false si identique (DM-088). */
final readonly class ZoneValidityResult
{
    /**
     * @param list<string> $createdSlotIds
     * @param list<string> $deletedSlotIds
     */
    public function __construct(
        public string $zoneId,
        public ValidityMode $mode,
        public array $createdSlotIds,
        public array $deletedSlotIds,
        public bool $changed,
    ) {
    }
}
