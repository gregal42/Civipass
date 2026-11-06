<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Mode de déplacement exclusif (DM-103) : à pied ou à vélo, ou en véhicule. */
enum TravelMode: string
{
    case FootBike = 'FOOT_BIKE';
    case Vehicle = 'VEHICLE';
}
