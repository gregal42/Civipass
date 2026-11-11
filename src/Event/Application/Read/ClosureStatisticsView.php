<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\PublishedClosureStatistics;

/**
 * Bilan de clôture présenté à UN lecteur : une ou deux sections publiées (globale pour l'organisatrice, propre à la commune de l'écran). Valeurs seuillées
 * seulement ; les compteurs exacts ne sortent jamais du domaine.
 */
final readonly class ClosureStatisticsView
{
    /**
     * @param array<string, string> $communeNames nom des communes de la ventilation, par identifiant
     */
    public function __construct(
        public ?PublishedClosureStatistics $global,
        public ?PublishedClosureStatistics $own,
        public array $communeNames,
        public bool $singleMunicipality,
    ) {
    }
}
