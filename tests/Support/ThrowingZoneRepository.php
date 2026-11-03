<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Doublure d'injection d'échec (pas de mock) : un VRAI collaborateur dont une étape échoue volontairement, pour prouver
 * que modification et audit sont annulés ensemble.
 */
final class ThrowingZoneRepository implements EventZoneRepository
{
    public function __construct(private readonly EventZoneRepository $inner)
    {
    }

    public function add(EventZone $zone): void
    {
        throw new \RuntimeException('Échec injecté à l\'ajout de la zone.');
    }

    public function find(Uuid $id): ?EventZone
    {
        return $this->inner->find($id);
    }

    public function findForMunicipality(Uuid $zoneId, Uuid $municipalityId): ?EventZone
    {
        return $this->inner->findForMunicipality($zoneId, $municipalityId);
    }

    public function findActiveByParticipation(Uuid $participationId): array
    {
        return $this->inner->findActiveByParticipation($participationId);
    }

    public function findByIdsInParticipation(array $zoneIds, Uuid $participationId): array
    {
        return $this->inner->findByIdsInParticipation($zoneIds, $participationId);
    }

    public function lock(Uuid $id): ?EventZone
    {
        return $this->inner->lock($id);
    }

    public function lockByIdsInParticipation(array $zoneIds, Uuid $participationId): array
    {
        return $this->inner->lockByIdsInParticipation($zoneIds, $participationId);
    }

    public function existsActivationReadyZone(Uuid $eventId): bool
    {
        return $this->inner->existsActivationReadyZone($eventId);
    }
}
