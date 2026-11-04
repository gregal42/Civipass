<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Domain\Validity\ValidityMode;

/**
 * Empreinte de la configuration de validité d'une zone : mode + identifiants des créneaux non supprimés. Chaque remplacement de
 * configuration crée de NOUVEAUX créneaux (DM-075), donc toute modification change l'empreinte ; une configuration identique
 * (aucune écriture, DM-088) la conserve. Un écran la lit pour la rapporter, EventZoneService::configureValidity la compare SOUS
 * les verrous de la zone : un seul mécanisme, celui du moteur.
 */
final class ValidityRevision
{
    /**
     * @param iterable<string> $slotIds identifiants (UUID) des créneaux non supprimés, dans n'importe quel ordre
     */
    public static function of(ValidityMode $mode, iterable $slotIds): string
    {
        $ids = [...$slotIds];
        sort($ids);

        return substr(hash('sha256', $mode->value.'|'.implode(',', $ids)), 0, 32);
    }
}
