<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Droits effectifs d'un laissez-passer à un instant donné, structurés par autorisation puis par zone. Il n'existe volontairement AUCUN
 * booléen « valide » global : un laissez-passer peut être valide pour une zone et pas pour une autre (DM-112, DM-115). `isFullyValid()`
 * n'est vrai que si TOUTES les zones (au moins une) le sont.
 */
final readonly class PermitRights
{
    /**
     * @param list<AuthorizationRights> $authorizations
     */
    public function __construct(
        public Uuid $permitId,
        public string $reference,
        public string $timezone,
        public \DateTimeImmutable $evaluatedAt,
        public array $authorizations,
    ) {
    }

    /**
     * @return list<ZoneRight>
     */
    public function zones(): array
    {
        $zones = [];
        foreach ($this->authorizations as $authorization) {
            foreach ($authorization->zones as $zone) {
                $zones[] = $zone;
            }
        }

        return $zones;
    }

    public function validZoneCount(): int
    {
        return \count(array_filter($this->zones(), static fn (ZoneRight $z): bool => $z->isValid()));
    }

    public function isFullyValid(): bool
    {
        $zones = $this->zones();

        return [] !== $zones && \count($zones) === $this->validZoneCount();
    }

    /**
     * Droits d'une zone donnée dans chaque autorisation qui la contient ; sinon un unique état `ZONE_NOT_AUTHORIZED`.
     *
     * @return list<ZoneRight>
     */
    public function forZone(Uuid $zoneId): array
    {
        $found = array_values(array_filter($this->zones(), static fn (ZoneRight $z): bool => $z->zoneId->equals($zoneId)));

        return [] !== $found ? $found : [new ZoneRight($zoneId, '', RightState::ZoneNotAuthorized)];
    }
}
