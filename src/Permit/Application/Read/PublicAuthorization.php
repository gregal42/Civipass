<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/**
 * Autorisation d'une commune telle que la voit le contrôle public. `validFrom`/`validUntil` : fenêtre de l'autorisation, donnée de contexte ;
 * ce sont les périodes de chaque zone qui font foi pour le contrôle. Zones actuellement associées d'abord, puis zones retirées.
 */
final readonly class PublicAuthorization
{
    /**
     * @param list<PublicZone> $zones
     */
    public function __construct(
        public string $municipalityName,
        public \DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public ?\DateTimeImmutable $revokedAt,
        public array $zones,
    ) {
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }
}
