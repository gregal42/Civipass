<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\AccessDenialReason;
use App\Event\Application\Support\ValidityRevision;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\ZoneStatus;
use App\Event\Domain\ZoneType;

/**
 * Zone telle qu'une commune la voit. « Non confirmée » est un état CALCULÉ depuis `confirmedAt` (DM-051), jamais un statut :
 * les statuts de zone restent Active et Suspendue. Les actions ne sont renseignées que pour les zones de la commune qui
 * consulte, décidées par la politique d'accès. Aucune entité, aucun champ interne.
 */
final readonly class ZoneView
{
    /**
     * @param list<ActionAvailability> $actions
     * @param list<SlotView>           $slots   créneaux non supprimés, dans l'ordre de la configuration (mode « périodes spécifiques »)
     */
    public function __construct(
        public string $zoneId,
        public string $label,
        public ZoneType $type,
        public ?string $description,
        public ZoneStatus $status,
        public ?\DateTimeImmutable $confirmedAt,
        public ValidityMode $validityMode,
        public bool $own,
        public array $actions = [],
        public array $slots = [],
    ) {
    }

    /**
     * Empreinte de la configuration de validité telle qu'affichée (mode + créneaux) : un formulaire la rapporte, le moteur
     * (EventZoneService::configureValidity) la compare sous verrou et refuse l'écriture si la configuration a changé entre-temps.
     */
    public function validityRevision(): string
    {
        return ValidityRevision::of($this->validityMode, array_map(static fn (SlotView $s): string => $s->slotId, $this->slots));
    }

    public function slot(string $slotId): ?SlotView
    {
        foreach ($this->slots as $slot) {
            if ($slot->slotId === $slotId) {
                return $slot;
            }
        }

        return null;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function action(EventAction $action): ?ActionAvailability
    {
        foreach ($this->actions as $candidate) {
            if ($candidate->action === $action) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Action à AFFICHER : refusée pour le rôle ou le statut de la commune, elle est absente (même règle que la fiche).
     */
    public function visibleAction(EventAction $action): ?ActionAvailability
    {
        $candidate = $this->action($action);

        return null !== $candidate && ($candidate->allowed || !\in_array($candidate->reason, [AccessDenialReason::RoleNotAllowed, AccessDenialReason::MunicipalityNotWritable], true)) ? $candidate : null;
    }
}
