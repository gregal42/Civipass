<?php

declare(strict_types=1);

namespace App\Event\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Lecture FRAÎCHE (jamais l'identité Doctrine, jamais un instantané de transaction ouverte) de l'état de clôture d'un événement. Ne verrouille pas :
 * le distributeur de notifications ne doit jamais prendre de verrou d'événement (ordre événement → participations → lignes d'outbox).
 */
interface EventStatusProbe
{
    /**
     * Un événement inconnu n'est pas « clos » : l'appelant refuse déjà une ressource inexistante par son propre contrôle.
     *
     * @phpstan-impure l'état change entre deux appels (clôture concurrente) : jamais mémorisé
     */
    public function isClosed(Uuid $eventId): bool;

    /** @phpstan-impure */
    public function isClosedByPermit(Uuid $permitId): bool;
}
