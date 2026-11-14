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

    /**
     * Rôles remis PAR DÉFAUT à un administrateur communal (DM-124) : il gère aussi les événements et instruit les demandes. Un cumul, pas une
     * hiérarchie : les rôles restent explicites, visibles et modifiables par l'administrateur dans l'écran Équipe.
     *
     * @return list<self>
     */
    public static function administratorDefaults(): array
    {
        return [self::MunicipalityAdmin, self::EventManager, self::Instructor];
    }

    /**
     * Applique le cumul par défaut quand l'administration est ACCORDÉE : un jeu qui contient le rôle d'administrateur reçoit aussi les deux autres.
     * Un jeu sans administration est renvoyé tel quel.
     *
     * @param list<self> $roles
     *
     * @return list<self>
     */
    public static function withAdministratorDefaults(array $roles): array
    {
        return \in_array(self::MunicipalityAdmin, $roles, true) ? self::administratorDefaults() : $roles;
    }
}
