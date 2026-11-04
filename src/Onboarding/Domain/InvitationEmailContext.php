<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Contenu d'un e-mail d'invitation : uniquement ce que le destinataire a le droit de savoir avant de s'authentifier. */
final readonly class InvitationEmailContext
{
    public function __construct(
        public string $organizerName,
        public string $eventName,
        public string $communeName,
        public ?string $message,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
