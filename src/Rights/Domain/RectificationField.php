<?php

declare(strict_types=1);

namespace App\Rights\Domain;

/** Champ concerné par une mention de rectification (codes seulement ; le texte de la mention est supprimé avec le dossier). */
enum RectificationField: string
{
    case Name = 'NAME';
    case Address = 'ADDRESS';
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case Plates = 'PLATES';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Nom ou prénom',
            self::Address => 'Adresse',
            self::Email => 'Adresse e-mail',
            self::Phone => 'Téléphone',
            self::Plates => 'Plaques',
            self::Other => 'Autre information',
        };
    }
}
