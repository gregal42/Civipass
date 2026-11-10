<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/** Un changement de zone planifié : `label` = libellé de la zone APRÈS le changement (retrait : celui qui était copié) ; `previousLabel` : avant (réintégration, correction). */
final readonly class ZoneChange
{
    public function __construct(public ZoneChangeKind $kind, public Uuid $zoneId, public string $label, public ?string $previousLabel = null)
    {
    }
}
