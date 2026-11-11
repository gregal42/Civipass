<?php

declare(strict_types=1);

namespace App\Event\Application;

use Symfony\Component\Uid\Uuid;

/** Port de la clôture vers les invitations (LOT 05.6.2, Q5) : révocation explicite des liens non consommés et non révoqués de l'événement. */
interface ClosureInvitationRevoker
{
    /** @return int nombre de liens révoqués */
    public function revokeUnconsumed(Uuid $eventId, \DateTimeImmutable $now): int;
}
