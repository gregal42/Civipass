<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\SubscriptionStatus;

/**
 * Statut administratif, abonnement et participation sont indépendants :
 * - créer un événement exige un statut ACTIVE ET un abonnement ACTIVE ;
 * - rejoindre un nouvel événement exige un statut ACTIVE, pas d'abonnement.
 */
final class EventCreationPolicy
{
    public function canCreateEvent(Municipality $municipality, SubscriptionStatus $subscription): bool
    {
        return MunicipalityStatus::Active === $municipality->status()
            && SubscriptionStatus::Active === $subscription;
    }

    public function canJoinNewEvent(Municipality $municipality): bool
    {
        return MunicipalityStatus::Active === $municipality->status();
    }
}
