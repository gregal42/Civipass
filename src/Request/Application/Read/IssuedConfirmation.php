<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\DurationMode;

/**
 * Confirmation d'une délivrance directe, lue en base pour l'agent émetteur : la portion de SA commune et sa décision, rien d'une autre commune.
 * `status` est celui de la décision (APPROVED) : une délivrance réussie n'est jamais présentée en attente. `consultable` : l'agent a aussi le
 * rôle INSTRUCTOR et peut donc ouvrir la fiche ; sinon aucun lien vers la consultation n'est proposé. `correctable` : INSTRUCTOR ou MUNICIPALITY_ADMIN d'une participation ACTIVE, donc autorisé à corriger l'identité du bénéficiaire (DM-111).
 */
final readonly class IssuedConfirmation
{
    /**
     * @param list<string> $zones
     */
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $eventName,
        public string $timezone,
        public string $municipalityName,
        public string $beneficiary,
        public array $zones,
        public DecisionOutcome $status,
        public ?DurationMode $durationMode,
        public \DateTimeImmutable $decidedAt,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public string $agent,
        public bool $consultable,
        public bool $correctable,
    ) {
    }
}
