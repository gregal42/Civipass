<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Dates du cycle de vie d'un événement (instants UTC ; l'affichage se fait dans le fuseau de l'événement). */
final readonly class EventLifecycleDates
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $activatedAt,
        public ?\DateTimeImmutable $suspendedAt,
        public ?\DateTimeImmutable $closedAt,
    ) {
    }
}
