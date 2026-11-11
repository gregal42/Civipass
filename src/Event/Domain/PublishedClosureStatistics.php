<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Statistiques PUBLIÉES (LOT 05.6.2) : dérivées des compteurs internes à la lecture, par profil de lecteur, après application du secret statistique.
 * Une valeur `null` est MASQUÉE (effectif sous le seuil) ; `masked` liste, à titre informatif, les indicateurs entièrement masqués.
 */
final readonly class PublishedClosureStatistics
{
    public const SCOPE_GLOBAL = 'GLOBAL';
    public const SCOPE_COMMUNE = 'COMMUNE';

    /**
     * @param array{approved: int, refused: int, pending: int, authorizations: int}|null $byStatus
     * @param list<array{municipalityId: string, dossiers: int}>|null                    $communes
     * @param list<string>                                                               $masked
     */
    public function __construct(
        public string $scope,
        public int $threshold,
        public ?int $dossiers,
        public ?array $byStatus,
        public ?array $communes,
        public array $masked,
    ) {
    }
}
