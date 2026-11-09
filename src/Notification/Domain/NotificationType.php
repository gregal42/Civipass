<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Types de message. Les quatre derniers sont la STRUCTURE réutilisable du LOT 05.5 (prolongation, zones, véhicules, révocation) : politique
 * d'ordonnancement et de regroupement prête, mais ni producteur ni gabarit avant le 05.5 (le compositeur refuse un type sans gabarit).
 */
enum NotificationType: string
{
    case PermitIssued = 'PERMIT_ISSUED';
    case AuthorizationAdded = 'AUTHORIZATION_ADDED';
    case PermitExtended = 'PERMIT_EXTENDED';
    case ZonesChanged = 'ZONES_CHANGED';
    case VehiclesChanged = 'VEHICLES_CHANGED';
    case AuthorizationRevoked = 'AUTHORIZATION_REVOKED';

    public function category(): ChangeCategory
    {
        return match ($this) {
            self::PermitIssued => ChangeCategory::FirstIssuance,
            self::AuthorizationRevoked => ChangeCategory::Revocation,
            self::AuthorizationAdded, self::PermitExtended, self::ZonesChanged, self::VehiclesChanged => ChangeCategory::Ordinary,
        };
    }
}
