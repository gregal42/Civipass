<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Types de zone du SFD (SCR-017). Aucune géométrie ni cartographie en V1.
 */
enum ZoneType: string
{
    case WholeMunicipality = 'WHOLE_MUNICIPALITY';
    case District = 'DISTRICT';
    case Street = 'STREET';
    case Locality = 'LOCALITY';
    case Custom = 'CUSTOM';
}
