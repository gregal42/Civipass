<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'un AJOUT de plaque (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.4) : facultatif. Jamais public, jamais notifié ; aucune plaque dans le commentaire. */
enum VehicleAddReason: string
{
    case NewVehicle = 'NEW_VEHICLE';
    case EntryError = 'ENTRY_ERROR';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NewVehicle => 'Nouveau véhicule',
            self::EntryError => 'Plaque omise à la saisie',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
