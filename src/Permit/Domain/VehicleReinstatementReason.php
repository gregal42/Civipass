<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Motifs d'une RÉACTIVATION de plaque retirée (DM-119 §8, validé le 10 novembre 2026 pour le LOT 05.5.4) : obligatoire. Jamais public, jamais notifié ; aucune plaque dans le commentaire. */
enum VehicleReinstatementReason: string
{
    case RemovedInError = 'REMOVED_IN_ERROR';
    case VehicleBackInUse = 'VEHICLE_BACK_IN_USE';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::RemovedInError => 'Retrait par erreur',
            self::VehicleBackInUse => 'Véhicule de nouveau utilisé',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
