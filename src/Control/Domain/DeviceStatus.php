<?php

declare(strict_types=1);

namespace App\Control\Domain;

/**
 * Cycle de vie d'un appareil de contrôle (DM-131) : en attente d'approbation → actif → désactivé. L'EXPIRATION n'est pas un statut : elle se calcule
 * à partir de `expires_at` (jamais de statut périmé en base).
 */
enum DeviceStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Deactivated = 'DEACTIVATED';
}
