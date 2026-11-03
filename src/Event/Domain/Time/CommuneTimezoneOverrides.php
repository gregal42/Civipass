<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Municipality\Domain\InseeCode;

/**
 * Exceptions de fuseau au niveau de la commune (Polynésie française : Marquises, Gambier).
 *
 * Aucune correspondance n'est supposée par le domaine : le contenu doit provenir d'une source officielle
 * (COG INSEE et liste officielle par archipel), fournie et validée avant intégration.
 */
interface CommuneTimezoneOverrides
{
    public function find(InseeCode $code): ?EventTimezone;
}
