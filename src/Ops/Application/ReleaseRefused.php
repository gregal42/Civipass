<?php

declare(strict_types=1);

namespace App\Ops\Application;

use App\Retention\Domain\RestoreItem;

/** Remise en service refusée : des anomalies bloquantes subsistent (la maintenance reste en place). */
final class ReleaseRefused extends \RuntimeException
{
    /** @param list<RestoreItem> $items */
    public function __construct(public readonly array $items)
    {
        parent::__construct(sprintf('%d anomalie(s) bloquante(s) non résolue(s) : la maintenance est maintenue.', \count($items)));
    }
}
