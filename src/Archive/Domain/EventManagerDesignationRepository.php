<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use Symfony\Component\Uid\Uuid;

interface EventManagerDesignationRepository
{
    public function add(EventManagerDesignation $designation): void;

    public function find(Uuid $id): ?EventManagerDesignation;

    /** La désignation ACTIVE d'un utilisateur sur une participation (événement × commune), ou null. `$lock` : verrou d'écriture. */
    public function findActive(Uuid $participationId, Uuid $userId, bool $lock = false): ?EventManagerDesignation;

    /** @return list<EventManagerDesignation> désignations actives de l'utilisateur sur les participations de CETTE commune */
    public function activeForUserInMunicipality(Uuid $userId, Uuid $municipalityId): array;

    /** @return list<EventManagerDesignation> désignations actives de l'utilisateur, toutes communes */
    public function activeForUser(Uuid $userId): array;

    /** @return list<EventManagerDesignation> historique complet d'une participation, plus récentes d'abord */
    public function forParticipation(Uuid $participationId): array;
}
