<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Formats de plaques françaises acceptés (DM-103). */
enum PlateFormat: string
{
    case Siv = 'SIV';
    case Fni = 'FNI';
}
