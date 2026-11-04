<?php

declare(strict_types=1);

namespace App\Municipality\Application;

/** Bilan d'une synchronisation du référentiel national. */
final readonly class SynchronizationReport
{
    public function __construct(
        public int $received,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $deactivated,
        public int $ignored,
        public bool $dryRun,
        public bool $partial,
    ) {
    }
}
