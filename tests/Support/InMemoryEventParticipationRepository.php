<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryEventParticipationRepository implements EventParticipationRepository
{
    /** @var list<EventParticipation> */
    private array $participations = [];

    public function add(EventParticipation $participation): void
    {
        $this->participations[] = $participation;
    }

    public function find(Uuid $id): ?EventParticipation
    {
        foreach ($this->participations as $participation) {
            if ($participation->id()->equals($id)) {
                return $participation;
            }
        }

        return null;
    }

    public function findForMunicipality(Uuid $participationId, Uuid $municipalityId): ?EventParticipation
    {
        $participation = $this->find($participationId);

        return null !== $participation && $participation->municipality()->id()->equals($municipalityId) ? $participation : null;
    }

    public function findByEventAndMunicipality(Uuid $eventId, Uuid $municipalityId): ?EventParticipation
    {
        foreach ($this->participations as $participation) {
            if ($participation->event()->id()->equals($eventId) && $participation->municipality()->id()->equals($municipalityId)) {
                return $participation;
            }
        }

        return null;
    }

    public function findByEvent(Uuid $eventId): array
    {
        return array_values(array_filter($this->participations, static fn (EventParticipation $p): bool => $p->event()->id()->equals($eventId)));
    }

    public function lock(Uuid $id): ?EventParticipation
    {
        return $this->find($id);
    }
}
