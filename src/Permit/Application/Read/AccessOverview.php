<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Écran agent « Laissez-passer » d'une demande approuvée : référence, bénéficiaire, liens (sans secret) et possibilité d'agir. */
final readonly class AccessOverview
{
    /**
     * @param list<AccessLinkView> $links
     */
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $requestReference,
        public string $eventName,
        public string $timezone,
        public string $lastName,
        public string $firstName,
        public array $links,
        public bool $canManage,
        public string $readOnlyReason,
        public NotificationOverview $notifications,
        public bool $hasRecipient,
    ) {
    }

    public function activeCount(): int
    {
        return \count(array_filter($this->links, static fn (AccessLinkView $l): bool => AccessLinkView::ACTIVE === $l->state));
    }
}
