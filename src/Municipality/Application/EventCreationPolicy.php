<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;

/**
 * Statut administratif, droits d'utilisation et participation sont indépendants (DM-070, DM-073, DM-076) :
 * - créer un événement exige un statut ACTIVE ET au moins un droit d'utilisation valide (abonnement actif,
 *   TRIAL valide ou accès commercial CWS) ; l'absence d'abonnement payant n'est pas un refus ;
 * - rejoindre un nouvel événement exige un statut ACTIVE, aucun droit d'utilisation ;
 * - l'expiration ou la révocation d'un droit n'affecte que les nouvelles créations, jamais la gestion
 *   des événements existants (cette politique ne s'y applique pas).
 */
final readonly class EventCreationPolicy
{
    public function __construct(private MunicipalityEntitlementProvider $entitlements)
    {
    }

    public function canCreateEvent(Municipality $municipality, \DateTimeImmutable $at): bool
    {
        if (MunicipalityStatus::Active !== $municipality->status()) {
            return false;
        }
        foreach ($this->entitlements->entitlementsFor($municipality, $at) as $entitlement) {
            if ($entitlement->isValidAt($at)) {
                return true;
            }
        }

        return false;
    }

    public function canJoinNewEvent(Municipality $municipality): bool
    {
        return MunicipalityStatus::Active === $municipality->status();
    }
}
