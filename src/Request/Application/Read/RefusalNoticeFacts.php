<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/**
 * Ce qu'il faut pour composer l'information d'un demandeur refusé (LOT 08.5), relu AU MOMENT de l'envoi : adresse courante, référence, événement, commune et
 * motif du référentiel. Jamais le commentaire libre, un nom, une adresse postale, une plaque ou un lien.
 */
final readonly class RefusalNoticeFacts
{
    public function __construct(
        public string $recipient,
        public string $reference,
        public string $eventName,
        public string $municipalityName,
        public ?string $municipalityContactEmail,
        public ?string $municipalityContactPhone,
        public ?string $reasonCode,
        public ?string $reasonLabel,
    ) {
    }
}
