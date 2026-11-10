<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Résultat d'une règle de zones (LOT 05.5.3) : les changements dans l'ordre d'écriture (retraits, réintégrations, ajouts, chacun par libellé), le nombre de zones
 * rattachées APRÈS l'opération (jamais zéro) et la présence d'un retrait (qui rend la notification prioritaire).
 */
final readonly class ZonePlan
{
    /**
     * @param list<ZoneChange> $changes
     * @param list<string>     $activeAfter identifiants (RFC 4122) des zones rattachées après l'opération
     */
    public function __construct(public array $changes, public int $attachedAfter, public array $activeAfter = [])
    {
    }

    public function hasRemoval(): bool
    {
        foreach ($this->changes as $change) {
            if (ZoneChangeKind::Removed === $change->kind) {
                return true;
            }
        }

        return false;
    }
}
