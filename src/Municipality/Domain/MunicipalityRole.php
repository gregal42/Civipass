<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Rôles communaux : explicites, cumulables, sans hiérarchie. Aucun rôle de contrôleur.
 */
enum MunicipalityRole: string
{
    case MunicipalityAdmin = 'MUNICIPALITY_ADMIN';
    case EventManager = 'EVENT_MANAGER';
    case Instructor = 'INSTRUCTOR';
}
