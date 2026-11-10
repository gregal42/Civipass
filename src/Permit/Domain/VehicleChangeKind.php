<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Nature d'un changement de plaque planifié (LOT 05.5.4). Un REMPLACEMENT produit deux changements liés par le même lot de révisions : la plaque qui sort
 * (`ReplacedOut`) et celle qui entre (`ReplacedIn`) ; le type de révision permet de le reconstituer sans s'appuyer sur l'horodatage.
 */
enum VehicleChangeKind: string
{
    case Removed = 'REMOVED';
    case ReplacedOut = 'REPLACED_OUT';
    case Reinstated = 'REINSTATED';
    case Added = 'ADDED';
    case ReplacedIn = 'REPLACED_IN';

    public function revisionType(): RevisionType
    {
        return match ($this) {
            self::Removed => RevisionType::VehicleRemoved,
            self::ReplacedOut => RevisionType::VehicleReplacedOut,
            self::Reinstated => RevisionType::VehicleReinstated,
            self::Added => RevisionType::VehicleAdded,
            self::ReplacedIn => RevisionType::VehicleReplacedIn,
        };
    }

    /** Action stockée dans `permit_revision_vehicle` : l'effet sur la ligne de plaque (un remplacement est lisible par le type de la révision). */
    public function action(): string
    {
        return match ($this) {
            self::Removed, self::ReplacedOut => 'REMOVED',
            self::Reinstated => 'REINSTATED',
            self::Added, self::ReplacedIn => 'ADDED',
        };
    }

    /** Retire des droits de contrôle (plaque qui ne sera plus reconnue) : notification prioritaire. */
    public function removesPlate(): bool
    {
        return self::Removed === $this || self::ReplacedOut === $this;
    }
}
