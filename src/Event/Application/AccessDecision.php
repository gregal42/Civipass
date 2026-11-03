<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Shared\Domain\OperationNotAllowed;

/**
 * Décision d'accès testable, indépendante de Symfony Security et des contrôleurs. Les services transactionnels
 * l'utilisent directement (orThrow) ; un contrôleur pourra la traduire en 404/403.
 */
final readonly class AccessDecision
{
    private function __construct(public ?AccessDenialReason $reason)
    {
    }

    public static function allow(): self
    {
        return new self(null);
    }

    public static function deny(AccessDenialReason $reason): self
    {
        return new self($reason);
    }

    public function isAllowed(): bool
    {
        return null === $this->reason;
    }

    /**
     * @throws OperationNotAllowed
     */
    public function orThrow(): void
    {
        if (null === $this->reason) {
            return;
        }
        throw new OperationNotAllowed(match ($this->reason) {
            AccessDenialReason::NoAccess => 'Ressource inaccessible.', AccessDenialReason::MunicipalityNotWritable => 'Votre commune ne permet pas cette modification (statut administratif).', AccessDenialReason::RoleNotAllowed => 'Votre rôle ne permet pas cette action.', AccessDenialReason::NoUsageRight => 'Votre commune ne dispose d\'aucun droit d\'utilisation valide pour créer un événement.', AccessDenialReason::EventClosed => 'L\'événement est clôturé.', AccessDenialReason::InvalidEventState => 'Cette action n\'est pas possible dans l\'état actuel de l\'événement.', AccessDenialReason::InvalidParticipationState => 'Cette action n\'est pas possible dans l\'état actuel de la participation.', AccessDenialReason::OrganizerParticipationBoundToEvent => 'La participation de la commune organisatrice se clôture avec l\'événement.', AccessDenialReason::ParticipationNotEditable => 'Les zones ne sont modifiables que lorsque la participation est active ou suspendue.', AccessDenialReason::ZoneDeleted => 'Cette zone est supprimée.', AccessDenialReason::SlotDeleted => 'Ce créneau est supprimé.',
        });
    }
}
