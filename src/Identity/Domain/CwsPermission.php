<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Habilitations CWS distinctes de `superAdmin` (DM-074) : le booléen donne l'administration générale de Sonata,
 * jamais ces permissions, qui ne sont pas attribuées automatiquement à tous les super-administrateurs.
 * Préparées au LOT TECH-02.1 ; leur attribution et les fonctions associées relèvent du lot Administration CWS.
 */
enum CwsPermission: string
{
    case Impersonate = 'CWS_IMPERSONATE';
    case ForceCloseEvent = 'CWS_FORCE_CLOSE_EVENT';
}
