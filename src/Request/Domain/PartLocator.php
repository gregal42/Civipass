<?php

declare(strict_types=1);

namespace App\Request\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Identifiants (jamais d'entités) d'une part communale, lus SANS verrou pour savoir quoi verrouiller : l'état n'est lu qu'après
 * acquisition des verrous, jamais depuis cet instantané (ordre DT-024 : événement → participation → zones → part).
 */
final readonly class PartLocator
{
    /**
     * @param list<Uuid> $zoneIds zones demandées à la commune, triées
     */
    public function __construct(
        public Uuid $partId,
        public Uuid $participationId,
        public Uuid $eventId,
        public array $zoneIds,
    ) {
    }
}
