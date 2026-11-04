<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Issue de l'envoi d'une invitation : l'invitation est TOUJOURS enregistrée ; l'e-mail peut avoir échoué (à renvoyer). */
final readonly class InvitationDispatchResult
{
    public function __construct(
        public string $participationId,
        public string $communeName,
        public bool $communeRegistered,
        public bool $emailSent,
        public string $maskedRecipient,
    ) {
    }
}
