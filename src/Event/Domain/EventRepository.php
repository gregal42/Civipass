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
     * Verrou d'écriture (`FOR UPDATE`) : sérialise modification, activation, suspension, clôture et invitation. À appeler
     * dans une transaction, AVANT toute lecture métier sensible à la concurrence.
     *
     * `EntityManager::find()` avec un mode de verrou pessimiste relit déjà l'entité sous ce verrou (lecture « courante »,
     * jamais l'instantané REPEATABLE READ) ; aucun `refresh()` simple ne doit suivre : il relirait un état périmé.
     */
    public function lock(Uuid $id): ?Event;

    /**
     * Verrou partagé (`FOR SHARE`) : compatible avec d'autres verrous partagés, incompatible avec un verrou d'écriture.
     * Pour les opérations qui ne modifient pas l'événement mais ne doivent pas coexister avec son activation ou sa clôture.
     */
    public function lockShared(Uuid $id): ?Event;
}
