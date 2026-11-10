<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

use App\Permit\Domain\PermitRights;

/**
 * Laissez-passer tel que le voit son bénéficiaire (page et PDF) : identité, déplacement, plaques, droits par commune et par zone
 * (PermitRights : un état par zone, jamais un statut global) et jeton de vérification du QR. Aucune adresse, e-mail, téléphone ni justificatif,
 * jamais le secret du lien (il n'est connu de personne après son affichage à l'agent).
 */
final readonly class BeneficiaryPass
{
    /**
     * @param list<string> $plates plaques autorisées, vide si aucun véhicule
     */
    public function __construct(
        public string $reference,
        public string $eventName,
        public string $timezone,
        public string $lastName,
        public string $firstName,
        public string $category,
        public string $travelMode,
        public array $plates,
        public PermitRights $rights,
        public string $verificationToken,
    ) {
    }

    /** Reste-t-il un droit actif ou à venir ? Faux : aucun PDF ne peut être généré (DM-119 §6). */
    public function hasLiveRights(): bool
    {
        return $this->rights->hasLiveRights();
    }

    /** Toutes les autorisations sont révoquées : la page affiche « Droits révoqués ». */
    public function isEntirelyRevoked(): bool
    {
        return $this->rights->isEntirelyRevoked();
    }
}
