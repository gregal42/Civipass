<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/** Zone d'une autorisation telle que le planificateur la voit : identifiant, libellé copié, retirée ou non (état opérationnel de la zone de l'événement ignoré). */
final readonly class ZoneRowState
{
    public function __construct(public Uuid $zoneId, public string $label, public bool $removed)
    {
    }
}
