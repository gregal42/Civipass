<?php

declare(strict_types=1);

namespace App\Event\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Port de la clôture vers les notifications (LOT 05.6.2) : DANS la transaction de clôture, après les verrous événement → participations, les
 * messages encore en attente (prioritaires compris) et les réservations à bail expiré sont supprimés avec le code `EVENT_CLOSED`. Un message dont
 * l'envoi est en cours (bail valide) n'est pas touché : son état reste exact (limite SMTP documentée).
 */
interface ClosureNotificationNeutralizer
{
    /** @return array{cancelled: int, secrets: int, links: int} nombres seulement : aucun identifiant de personne */
    public function neutralize(Uuid $eventId, \DateTimeImmutable $now): array;
}
