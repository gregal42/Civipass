<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Nature d'une révision des droits d'un laissez-passer (LOT 05.5, DM-119). Une valeur par opération : les suivantes sont ajoutées avec leur
 * sous-lot (périodes livrées au 05.5.2, zones au 05.5.3, plaques au 05.5.4, QR au 05.5.5). Valeurs stockées : jamais renommées.
 */
enum RevisionType: string
{
    case AuthorizationRevoked = 'AUTHORIZATION_REVOKED';
    case PeriodExtended = 'PERIOD_EXTENDED';
    case PeriodResumed = 'PERIOD_RESUMED';
    case PeriodShortened = 'PERIOD_SHORTENED';
    case ZoneAdded = 'ZONE_ADDED';
    case ZoneRemoved = 'ZONE_REMOVED';
    case ZoneReinstated = 'ZONE_REINSTATED';
    case ZoneLabelCorrected = 'ZONE_LABEL_CORRECTED';
    case VehicleAdded = 'VEHICLE_ADDED';
    case VehicleRemoved = 'VEHICLE_REMOVED';
    case VehicleReinstated = 'VEHICLE_REINSTATED';
    /** Plaque qui SORT d'un remplacement ; liée à `VehicleReplacedIn` par le même lot de révisions. */
    case VehicleReplacedOut = 'VEHICLE_REPLACED_OUT';
    case VehicleReplacedIn = 'VEHICLE_REPLACED_IN';
    /** Remplacement du QR code (jeton de vérification) du laissez-passer (LOT 05.5.5). */
    case TokenReplaced = 'TOKEN_REPLACED';

    /** Libellé pour la commune qui a effectué la révision. */
    public function label(): string
    {
        return match ($this) {
            self::AuthorizationRevoked => 'Autorisation révoquée',
            self::PeriodExtended => 'Période prolongée',
            self::PeriodResumed => 'Reprise des droits',
            self::PeriodShortened => 'Période raccourcie',
            self::ZoneAdded => 'Zone ajoutée',
            self::ZoneRemoved => 'Zone retirée',
            self::ZoneReinstated => 'Zone réintégrée',
            self::ZoneLabelCorrected => 'Libellé de zone corrigé',
            self::VehicleAdded => 'Plaque ajoutée',
            self::VehicleRemoved => 'Plaque retirée',
            self::VehicleReinstated => 'Plaque réactivée',
            self::VehicleReplacedOut => 'Plaque remplacée (ancienne plaque)',
            self::VehicleReplacedIn => 'Plaque remplacée (nouvelle plaque)',
            self::TokenReplaced => 'QR code remplacé',
        };
    }

    /** Libellé de la ligne NEUTRE montrée aux autres communes (DM-119 §7) : le type seulement, jamais un motif ni un droit. */
    public function neutralLabel(): string
    {
        return match ($this) {
            self::AuthorizationRevoked => 'Autorisation révoquée',
            self::PeriodExtended => 'Période prolongée',
            self::PeriodResumed => 'Reprise des droits',
            self::PeriodShortened => 'Période raccourcie',
            self::ZoneAdded, self::ZoneRemoved, self::ZoneReinstated => 'Zones modifiées',
            self::ZoneLabelCorrected => 'Libellé de zone corrigé',
            self::VehicleAdded, self::VehicleRemoved, self::VehicleReinstated, self::VehicleReplacedOut, self::VehicleReplacedIn => 'Véhicules modifiés',
            self::TokenReplaced => 'QR code remplacé',
        };
    }

    public function isVehicle(): bool
    {
        return \in_array($this, [self::VehicleAdded, self::VehicleRemoved, self::VehicleReinstated, self::VehicleReplacedOut, self::VehicleReplacedIn], true);
    }
}
