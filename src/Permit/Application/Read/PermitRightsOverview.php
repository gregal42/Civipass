<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

use App\Permit\Domain\AuthorizationRights;

/**
 * Écran « Droits du laissez-passer » d'une commune (LOT 05.5.1) : SON autorisation (zones et états), la possibilité de la révoquer et l'historique
 * (détaillé pour ses propres révisions, neutre pour celles des autres communes). Aucune adresse, aucun e-mail, aucune plaque, aucun jeton.
 */
final readonly class PermitRightsOverview
{
    /**
     * @param list<RevisionLine> $history de la plus récente à la plus ancienne
     */
    public function __construct(
        public string $reference,
        public string $requestReference,
        public string $eventName,
        public string $timezone,
        public string $firstName,
        public string $lastName,
        public string $municipalityName,
        public AuthorizationRights $rights,
        public bool $canRevoke,
        public string $readOnlyReason,
        public array $history,
        public \DateTimeImmutable $at,
        /** `extend` (prolonger), `resume` (reprendre après expiration) ou nul si l'action est impossible, avec `extendBlockedReason`. */
        public ?string $extendMode = null,
        public string $extendBlockedReason = '',
        public bool $canShorten = false,
        public string $shortenBlockedReason = '',
        /** @var list<ZoneRowView> zones de l'autorisation : rattachées puis retirées */
        public array $zoneRows = [],
        /** @var list<ZoneOption> zones proposables jamais rattachées */
        public array $addableZones = [],
        /** @var list<ZoneOption> zones retirées qui peuvent être réintégrées */
        public array $reinstatableZones = [],
        public bool $canAddZones = false,
        public string $addZonesBlockedReason = '',
        public bool $canChangeZones = false,
        public string $changeZonesBlockedReason = '',
        public bool $canCorrectLabels = false,
        public string $correctLabelsBlockedReason = '',
        public ?VehiclesPanel $vehicles = null,
        public ?QrPanel $qr = null,
    ) {
    }

    public function isRevoked(): bool
    {
        return null !== $this->rights->revokedAt;
    }
}
