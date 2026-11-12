<?php

declare(strict_types=1);

namespace App\Rights\Domain;

/** Méthode ATTESTÉE par l'administrateur (Civipass ne prouve pas l'identité, il trace qui l'atteste). */
enum RightsIdentityMethod: string
{
    case ReferenceAndEmail = 'REFERENCE_AND_EMAIL';
    case DocumentChecked = 'DOCUMENT_CHECKED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::ReferenceAndEmail => 'Référence et coordonnées du dossier concordantes',
            self::DocumentChecked => 'Pièce d’identité vérifiée',
            self::Other => 'Autre moyen de vérification',
        };
    }
}
