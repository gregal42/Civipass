<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Types de message. Producteurs et gabarits livrés : mise à disposition, nouvelle commune, révocation (05.5.1), prolongation, reprise et raccourcissement (05.5.2) ;
 * zones (05.5.3) et plaques (05.5.4) : ordinaire ou prioritaire selon la présence d'un retrait ; remplacement du QR (05.5.5) : prioritaire ; le compositeur refuse un type sans gabarit.
 */
enum NotificationType: string
{
    case PermitIssued = 'PERMIT_ISSUED';
    case AuthorizationAdded = 'AUTHORIZATION_ADDED';
    case PermitExtended = 'PERMIT_EXTENDED';
    case ZonesChanged = 'ZONES_CHANGED';
    case VehiclesChanged = 'VEHICLES_CHANGED';
    case AuthorizationRevoked = 'AUTHORIZATION_REVOKED';
    case PermitShortened = 'PERMIT_SHORTENED';
    case PermitResumed = 'PERMIT_RESUMED';
    case ZonesRemoved = 'ZONES_REMOVED';
    case VehiclesRemoved = 'VEHICLES_REMOVED';
    case QrReplaced = 'QR_REPLACED';

    public function category(): ChangeCategory
    {
        return match ($this) {
            self::PermitIssued => ChangeCategory::FirstIssuance,
            self::AuthorizationRevoked, self::PermitShortened, self::ZonesRemoved, self::VehiclesRemoved, self::QrReplaced => ChangeCategory::Revocation,
            self::AuthorizationAdded, self::PermitExtended, self::PermitResumed, self::ZonesChanged, self::VehiclesChanged => ChangeCategory::Ordinary,
        };
    }
}
