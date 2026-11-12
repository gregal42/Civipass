<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use Symfony\Component\Uid\Uuid;

interface StatisticsCoordinationGrantRepository
{
    public function add(StatisticsCoordinationGrant $grant): void;

    public function find(Uuid $id): ?StatisticsCoordinationGrant;

    public function findActive(Uuid $eventId, Uuid $userId, bool $lock = false): ?StatisticsCoordinationGrant;

    /** @return list<StatisticsCoordinationGrant> habilitations actives sur les événements ORGANISÉS par cette commune */
    public function activeForUserInOrganizer(Uuid $userId, Uuid $municipalityId): array;

    /** @return list<StatisticsCoordinationGrant> */
    public function activeForUser(Uuid $userId): array;

    /** @return list<StatisticsCoordinationGrant> historique d'un événement, plus récentes d'abord */
    public function forEvent(Uuid $eventId): array;
}
