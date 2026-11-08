<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\TravelMode;

/**
 * Fiche d'une demande vue par UNE commune : informations communes du dépôt, zones et statut de la seule portion de cette
 * commune. Aucune donnée d'une autre commune (zones, statut, noms) n'entre dans ce modèle.
 */
final readonly class MunicipalRequestDetail
{
    /**
     * @param list<string>                $zones       zones demandées dans la commune courante
     * @param list<string>                $plates
     * @param list<RequestAttachmentView> $attachments tous les justificatifs du dépôt (communs)
     * @param list<RequestZoneView>       $zoneViews   les mêmes zones, avec mode de validité et suspension
     */
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $eventId,
        public string $eventName,
        public string $timezone,
        public \DateTimeImmutable $submittedAt,
        public MunicipalRequestStatus $status,
        public ParticipationStatus $participationStatus,
        public string $lastName,
        public string $firstName,
        public ?string $address,
        public ?string $email,
        public ?string $phone,
        public string $categoryLabel,
        public string $motifLabel,
        public ?string $motifDetail,
        public array $zones,
        public TravelMode $travelMode,
        public array $plates,
        public array $attachments,
        public EventStatus $eventStatus,
        public ?\DateTimeImmutable $eventEndsAt,
        public array $zoneViews,
        public ?DecisionView $decision,
    ) {
    }
}
