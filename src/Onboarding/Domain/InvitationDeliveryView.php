<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Dernier e-mail d'invitation d'une participation, tel que l'organisatrice peut le voir : adresse masquée, jamais de jeton. */
final readonly class InvitationDeliveryView
{
    public function __construct(
        public string $participationId,
        public string $maskedRecipient,
        public InvitationDeliveryStatus $status,
        public ?\DateTimeImmutable $attemptedAt,
        public \DateTimeImmutable $expiresAt,
        public bool $expired,
    ) {
    }
}
