<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\AccessDenialReason;
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
    ) {
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
