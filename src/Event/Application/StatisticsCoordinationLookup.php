<?php

declare(strict_types=1);

namespace App\Event\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Port du bilan de clôture vers l'habilitation de coordination statistique (L5-36), portée par le module Archive : l'événement ne dépend pas des archives.
 * Lecture à chaque appel, jamais mémorisée.
 */
interface StatisticsCoordinationLookup
{
    /** Habilitation ACTIVE de l'utilisateur sur l'événement ; ne dit rien de son rôle (relu par l'appelant). */
    public function hasActiveGrant(Uuid $eventId, Uuid $userId): bool;
}
