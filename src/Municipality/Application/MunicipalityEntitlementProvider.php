<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\Entitlement;
use App\Municipality\Domain\Municipality;

/**
 * Droits d'utilisation d'une commune (abonnement, TRIAL, accès commercial CWS) à un instant donné.
 *
 * Contrat consommé par EventCreationPolicy : les sources de droits évoluent (module Abonnements, etc.)
 * sans modifier les services métier du module Event.
 */
interface MunicipalityEntitlementProvider
{
    /**
     * @return list<Entitlement> droits valides à l'instant donné ; liste vide = aucun droit
     */
    public function entitlementsFor(Municipality $municipality, \DateTimeImmutable $at): array;
}
