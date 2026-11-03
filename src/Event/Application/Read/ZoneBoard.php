<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/**
 * Les zones d'un événement vues par UNE commune (DM-007, DM-084) : les siennes avec leurs actions, celles des autres communes
 * en lecture seule quand sa participation le permet. `ownParticipationId` vient du serveur (jamais du client) ;
 * `create` est la disponibilité de « Créer une zone » pour SA participation.
 */
final readonly class ZoneBoard
{
    /**
     * @param list<ZoneGroup> $groups
     */
    public function __construct(
        public EventDetail $detail,
        public array $groups,
        public ?string $ownParticipationId,
        public ?ActionAvailability $create,
    ) {
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (ZoneGroup $g): int => \count($g->zones), $this->groups));
    }

    public function zone(string $zoneId): ?ZoneView
    {
        foreach ($this->groups as $group) {
            foreach ($group->zones as $zone) {
                if ($zone->zoneId === $zoneId) {
                    return $zone;
                }
            }
        }

        return null;
    }

    public function groupOf(string $zoneId): ?ZoneGroup
    {
        foreach ($this->groups as $group) {
            foreach ($group->zones as $zone) {
                if ($zone->zoneId === $zoneId) {
                    return $group;
                }
            }
        }

        return null;
    }
}
