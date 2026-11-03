<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Statuts du SFD : aucun statut « À confirmer » (la confirmation est `confirmedAt`, DM-051).
 * La suppression logique (`deletedAt`) et la suspension sont deux mécanismes distincts.
 */
enum ZoneStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
