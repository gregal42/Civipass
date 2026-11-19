<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum TwoFactorState: string
{
    /** Aucun second facteur : inscription à faire. */
    case None = 'NONE';
    /** Secret généré mais pas encore confirmé par un premier code : ne protège rien. */
    case Pending = 'PENDING';
    /** Confirmé : un code est exigé à chaque session. */
    case Active = 'ACTIVE';
}
