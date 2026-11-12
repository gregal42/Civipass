<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

/** Événement dormant : références et dates seulement, jamais de donnée personnelle. */
final readonly class DormantEvent
{
    /** Alerte à envoyer (jamais alerté, ou rappel échu). */
    public const ALERTABLE = 'ALERTABLE';
    /** Prise d'alerte non confirmée dont le bail a expiré : à rejouer. */
    public const RETRY = 'RETRY';
    /** Prise d'alerte en cours (bail valide) : une autre exécution s'en charge. */
    public const LEASE_PENDING = 'LEASE_PENDING';
    public const ALERTED_RECENTLY = 'ALERTED_RECENTLY';
    public const SNOOZED = 'SNOOZED';

    public function __construct(
        public string $eventId,
        public string $reference,
        public string $name,
        public string $organizerId,
        public \DateTimeImmutable $lastActivity,
        public int $inactiveDays,
        public string $state,
        public ?\DateTimeImmutable $alertedAt,
        public ?\DateTimeImmutable $snoozedUntil,
    ) {
    }

    public function claimable(): bool
    {
        return self::ALERTABLE === $this->state || self::RETRY === $this->state;
    }
}
