<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\NationalCommuneRecord;

/**
 * Source officielle du référentiel des communes (port). L'implémentation de production interroge l'API Géo ; un fichier JSON
 * local de même format sert aux imports hors ligne et aux tests.
 */
interface NationalCommuneSource
{
    /** Nom court de la source, conservé sur chaque commune importée (ex. « geo.api.gouv.fr »). */
    public function name(): string;

    /**
     * Liste COMPLÈTE des communes de la source.
     *
     * @return list<NationalCommuneRecord>
     *
     * @throws NationalCommuneSourceUnavailable source injoignable ou réponse inexploitable
     */
    public function fetchAll(): array;
}
