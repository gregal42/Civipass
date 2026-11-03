<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Isolation communale : tout accès pour le compte d'une commune passe par findAccessibleTo / listForMunicipality,
 * qui ne renvoient que les événements organisés par cette commune ou auxquels elle participe (même invitée).
 * Un identifiant d'événement fourni par un client n'est jamais fiable.
 */
interface EventRepository
{
    public function add(Event $event): void;

    /** Accès interne (services déjà autorisés) : sans filtre de commune. */
    public function find(Uuid $id): ?Event;

    public function findByPublicReference(PublicReference $reference): ?Event;

    public function findAccessibleTo(Uuid $eventId, Uuid $municipalityId): ?Event;

    /**
     * @return list<Event> du plus récent au plus ancien
     */
    public function listForMunicipality(Uuid $municipalityId, int $limit = 100): array;

    /**
     * Verrou d'écriture puis rafraîchissement : sérialise activation, suspension et clôture. À appeler dans une transaction.
     */
    public function lock(Uuid $id): ?Event;
}
