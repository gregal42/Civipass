<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Event\Application\ClosureInvitationRevoker;
use App\Onboarding\Domain\InvitationLinkRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Les invitations non consommées d'un événement clos sont révoquées explicitement (LOT 05.6.2, Q5) : l'état est lisible en base, pas seulement déduit. */
#[AsAlias(ClosureInvitationRevoker::class)]
final readonly class EventClosureInvitations implements ClosureInvitationRevoker
{
    public function __construct(private InvitationLinkRepository $links)
    {
    }

    public function revokeUnconsumed(Uuid $eventId, \DateTimeImmutable $now): int
    {
        return $this->links->revokeOpenOfEvent($eventId, $now);
    }
}
