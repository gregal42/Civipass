<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Read\NotificationOverview;
use Symfony\Component\Uid\Uuid;

/**
 * Port possédé par Permit, implémenté par Notification (LOT 05.3.4) : état des notifications d'un laissez-passer tel que le voit un agent.
 * Lecture seule. `$ownAuthorizationIds` : autorisations de la commune de l'agent ; les extensions des AUTRES communes ne sont jamais exposées.
 */
interface NotificationStatusProvider
{
    /**
     * @param list<Uuid> $ownAuthorizationIds
     */
    public function overviewFor(Uuid $permitId, array $ownAuthorizationIds): NotificationOverview;
}
