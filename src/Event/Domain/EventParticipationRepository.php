<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Symfony\Component\Uid\Uuid;

interface EventParticipationRepository
{
    public function add(EventParticipation $participation): void;

    /** Accès interne (services déjà autorisés) : sans filtre de commune. */
    public function find(Uuid $id): ?EventParticipation;

    /** Une commune ne peut atteindre que SA participation : l'identifiant client n'est jamais fiable. */
    public function findForMunicipality(Uuid $participationId, Uuid $municipalityId): ?EventParticipation;

    public function findByEventAndMunicipality(Uuid $eventId, Uuid $municipalityId): ?EventParticipation;

    /**
     * Toutes les participations d'un événement (usage de l'organisatrice : clôture, agrégats sans donnée de dossier).
     *
     * @return list<EventParticipation>
     */
    public function findByEvent(Uuid $eventId): array;

    /**
     * Verrou d'écriture (relecture sous verrou, sans `refresh()` simple) : sérialise les opérations sur les zones et validités. À appeler dans une transaction.
     */
    public function lock(Uuid $id): ?EventParticipation;
}
