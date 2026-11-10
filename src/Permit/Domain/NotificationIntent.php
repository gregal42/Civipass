<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Intention de notification émise par le module Permit (LOT 05.3.1, DT-049). Identifiants seulement : aucune adresse, aucun nom, aucune plaque,
 * aucun secret. `hasRecipient` dit si une adresse e-mail exploitable existe au moment de l'événement ; l'adresse elle-même n'est jamais copiée.
 */
final readonly class NotificationIntent
{
    public function __construct(
        public NotificationKind $kind,
        public Uuid $permitId,
        public ?Uuid $authorizationId,
        public \DateTimeImmutable $occurredAt,
        public bool $hasRecipient,
        public ?Uuid $revisionId = null,
    ) {
    }

    /** Clé de dédoublonnage : un événement métier = au plus une ligne d'outbox, quel que soit le nombre de rejeux. */
    public function deduplicationKey(): string
    {
        return match ($this->kind) {
            NotificationKind::PermitIssued => 'PERMIT_ISSUED:'.$this->permitId->toRfc4122(),
            NotificationKind::AuthorizationAdded => 'AUTHORIZATION_ADDED:'.($this->authorizationId ?? throw new \LogicException('Autorisation requise.'))->toRfc4122(),
            NotificationKind::AuthorizationRevoked,
            NotificationKind::PermitExtended,
            NotificationKind::PermitResumed,
            NotificationKind::ZonesChanged,
            NotificationKind::ZonesRemoved,
            NotificationKind::VehiclesChanged,
            NotificationKind::VehiclesRemoved,
            NotificationKind::QrReplaced,
            NotificationKind::PermitShortened => $this->kind->value.':'.($this->revisionId ?? throw new \LogicException('Révision requise.'))->toRfc4122(),
        };
    }
}
