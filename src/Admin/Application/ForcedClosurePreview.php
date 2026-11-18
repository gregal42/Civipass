<?php

declare(strict_types=1);

namespace App\Admin\Application;

/**
 * Ce que l'opérateur CWS voit AVANT la clôture exceptionnelle (DM-065, DM-005) : l'événement, ses participations encore ouvertes et des COMPTEURS. Aucune donnée
 * personnelle, aucune référence ni aucun identifiant de demande ou de laissez-passer.
 */
final readonly class ForcedClosurePreview
{
    /**
     * @param list<array{municipality: string, status: string, organizer: bool}> $participations
     */
    public function __construct(
        public string $eventId,
        public string $reference,
        public string $name,
        public string $status,
        public string $organizerName,
        public array $participations,
        public int $requests,
        public int $pending,
        public int $authorizations,
    ) {
    }
}
