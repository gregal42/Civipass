<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Cycle de vie d'un événement (SFD §2, DM-077). Source unique des transitions autorisées ;
 * la clôture est définitive, y compris depuis DRAFT.
 */
enum EventStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Closed],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isClosed(): bool
    {
        return self::Closed === $this;
    }
}
