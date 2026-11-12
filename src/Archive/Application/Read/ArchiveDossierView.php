<?php

declare(strict_types=1);

namespace App\Archive\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/**
 * Dossier communal archivé, tel que lu par UNE commune : données COMMUNES (identité, contact, adresse, plaques, pièces listées) + données PROPRES à la commune
 * (décision, autorisation, historique) ; les autres communes n'apparaissent que par un décompte NEUTRE. Lecture seule : aucune action n'en découle.
 */
final readonly class ArchiveDossierView
{
    /**
     * @param list<string>                                                      $plates
     * @param list<array{id: string, name: string, size: int, mime: string}>    $attachments
     * @param list<string>                                                      $zones
     * @param list<array{type: string, at: \DateTimeImmutable}>                 $history
     * @param list<array{fields: string, text: string, at: \DateTimeImmutable}> $mentions    mentions de rectification (LOT 05.6.6) : ajoutées à côté de la valeur d'origine, jamais en remplacement
     */
    public function __construct(
        public string $consultationId,
        public string $dossierId,
        public \DateTimeImmutable $consultationValidUntil,
        public string $reference,
        public string $eventName,
        public string $categoryLabel,
        public string $motifLabel,
        public string $travelMode,
        public \DateTimeImmutable $submittedAt,
        public string $lastName,
        public string $firstName,
        public ?string $address,
        public ?string $email,
        public ?string $phone,
        public array $plates,
        public array $attachments,
        public MunicipalRequestStatus $status,
        public ?\DateTimeImmutable $decidedAt,
        public ?string $decisionOutcome,
        public ?string $refusalLabel,
        public ?string $refusalComment,
        public array $zones,
        public ?string $permitReference,
        public ?bool $authorizationRevoked,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public array $history,
        public int $otherMunicipalityChanges,
        public ?\DateTimeImmutable $purgeEligibleAt,
        public bool $holdActive,
        public string $holdReasonLabel,
        public ?\DateTimeImmutable $holdReviewDueAt,
        public bool $canManageHold,
        public array $mentions = [],
    ) {
    }
}
