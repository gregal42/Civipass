<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/**
 * Ce que l'on peut dire d'un lien AVANT toute authentification : uniquement le contenu de l'e-mail (organisatrice, événement,
 * commune invitée, message). Rien d'autre sur l'événement, ses zones ou ses communes. Les champs de contexte ne sont renseignés
 * que pour un lien utilisable.
 */
final readonly class LinkResolution
{
    public function __construct(
        public LinkStatus $status,
        public ?InvitationLink $link = null,
        public string $organizerName = '',
        public string $eventName = '',
        public string $communeName = '',
        public ?string $message = null,
        public ?string $municipalityId = null,
        public ?string $eventId = null,
        public bool $communeAdministered = false,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function isUsable(): bool
    {
        return LinkStatus::Usable === $this->status && null !== $this->link;
    }
}
