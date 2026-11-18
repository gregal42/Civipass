<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Moyen par lequel un agent a informé un demandeur refusé (LOT 08.5, DM-136). */
enum ManualChannel: string
{
    case Phone = 'PHONE';
    case Counter = 'COUNTER';
    case Post = 'POST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Téléphone',
            self::Counter => 'Guichet',
            self::Post => 'Courrier',
            self::Other => 'Autre moyen',
        };
    }
}
