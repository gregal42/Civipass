<?php

declare(strict_types=1);

namespace App\Rights\Domain;

enum RightsExtensionReason: string
{
    case Complexity = 'COMPLEXITY';
    case NumberOfRequests = 'NUMBER_OF_REQUESTS';

    public function label(): string
    {
        return match ($this) {
            self::Complexity => 'Complexité de la demande',
            self::NumberOfRequests => 'Nombre de demandes',
        };
    }
}
