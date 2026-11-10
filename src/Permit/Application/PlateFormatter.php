<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Domain\PermitVehicle;
use App\Request\Domain\PlateFormat;

/**
 * Plaque telle qu'elle s'affiche (page bénéficiaire, PDF, vérification publique) : SIV groupé `AB-123-CD`, tout autre format tel que saisi.
 */
final class PlateFormatter
{
    public static function format(PermitVehicle $vehicle): string
    {
        return self::formatRegistration($vehicle->registration(), $vehicle->format());
    }

    public static function formatRegistration(string $registration, PlateFormat $format): string
    {
        if (PlateFormat::Siv === $format && 1 === preg_match('/^([A-Z]{2})(\d{3})([A-Z]{2})$/', $registration, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }

        return $registration;
    }
}
