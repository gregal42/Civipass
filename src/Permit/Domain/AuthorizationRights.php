<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\DurationMode;
use Symfony\Component\Uid\Uuid;

/**
 * Droits d'UNE autorisation communale à l'instant évalué : sa fenêtre courante, sa révocation éventuelle et l'état de chacune de ses zones.
 * Les zones d'une même autorisation peuvent avoir des états différents au même instant.
 */
final readonly class AuthorizationRights
{
    /**
     * @param list<ZoneRight> $zones
     */
    public function __construct(
        public Uuid $authorizationId,
        public Uuid $municipalityId,
        public string $municipalityName,
        public DurationMode $durationMode,
        public \DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public ?\DateTimeImmutable $revokedAt,
        public array $zones,
    ) {
    }

    /**
     * @return list<ZoneRight>
     */
    public function validZones(): array
    {
        return array_values(array_filter($this->zones, static fn (ZoneRight $z): bool => $z->isValid()));
    }
}
