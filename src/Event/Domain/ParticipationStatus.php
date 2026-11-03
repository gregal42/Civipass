<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Cycle de vie d'une participation communale. `INVITED → CLOSED` n'a lieu que par la clôture de l'événement
 * (une invitation ne se décline pas, DM-037) ; ce contrôle est porté par EventParticipation.
 */
enum ParticipationStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Invited => [self::Active, self::Closed],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
