<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'un REMPLACEMENT de plaque (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.4) : obligatoire. Jamais public, jamais notifié ; aucune plaque dans le commentaire. */
enum VehicleReplacementReason: string
{
    case VehicleChanged = 'VEHICLE_CHANGED';
    case EntryError = 'ENTRY_ERROR';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::VehicleChanged => 'Changement de véhicule',
            self::EntryError => 'Erreur de saisie',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
