<?php

declare(strict_types=1);

namespace App\Retention\Application;

/** La purge réelle est refusée : l'interrupteur `RETENTION_PURGE_ENABLED` vaut 0 (politique non validée). */
final class PurgeDisabled extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Purge désactivée : RETENTION_PURGE_ENABLED=0 (la politique de conservation n\'est pas validée ; ne l\'activer qu\'avec un accord explicite).');
    }
}
