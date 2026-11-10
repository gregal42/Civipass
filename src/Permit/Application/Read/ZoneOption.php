<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Zone proposée à l'ajout ou à la réintégration : identifiant et libellé courant. */
final readonly class ZoneOption
{
    public function __construct(public string $id, public string $label)
    {
    }
}
