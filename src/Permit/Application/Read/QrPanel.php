<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/**
 * Remplacement du QR code d'un laissez-passer pour l'agent d'une commune (LOT 05.5.5, indicatif : tout est relu sous verrou à l'écriture). `closeToLast` : le dernier
 * remplacement date de moins d'une heure (la référence devra être ressaisie). `otherMunicipalities` : les AUTRES communes titulaires d'une autorisation non révoquée,
 * annoncées avant confirmation de l'effet global. Aucun jeton.
 */
final readonly class QrPanel
{
    /** @param list<string> $otherMunicipalities */
    public function __construct(
        public bool $canReplace,
        public string $blockedReason,
        public string $reference,
        public array $otherMunicipalities,
        public bool $closeToLast,
        public int $replacementCount,
    ) {
    }
}
