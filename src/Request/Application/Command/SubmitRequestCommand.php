<?php

declare(strict_types=1);

namespace App\Request\Application\Command;

use App\Request\Domain\TravelMode;

/**
 * Données déclarées d'un dépôt, non fiables : tous les identifiants sont revérifiés côté serveur. Catégorie et motif
 * se désignent par leur code stable ; les communes se déduisent des zones demandées (jamais fournies par le client).
 */
final readonly class SubmitRequestCommand
{
    /**
     * @param list<string>              $plates      plaques saisies en clair (vide en déplacement à pied ou à vélo)
     * @param list<string>              $zoneIds     identifiants des zones demandées, toutes communes confondues
     * @param list<AttachmentReference> $attachments justificatifs facultatifs, déjà téléversés en zone temporaire (5 au plus)
     */
    public function __construct(
        public string $eventId,
        public string $lastName,
        public string $firstName,
        public ?string $address,
        public ?string $email,
        public ?string $phone,
        public string $categoryCode,
        public string $motifCode,
        public ?string $motifDetail,
        public TravelMode $travelMode,
        public array $plates,
        public array $zoneIds,
        public bool $privacyAttested,
        public ?string $privacyNoticeVersion = null,
        public array $attachments = [],
    ) {
    }
}
