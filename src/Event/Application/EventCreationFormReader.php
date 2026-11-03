<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\EventCreationContext;
use App\Event\Domain\Time\TerritoryTimezoneResolver;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Contexte du formulaire de création pour la commune de l'URL (identifiant non fiable : membership effectif exigé, refus
 * uniforme sinon). Le droit de créer est celui de la politique d'accès (rôle, statut de la commune, droit d'utilisation) ;
 * le service de création le décide à nouveau sous transaction.
 */
final readonly class EventCreationFormReader
{
    public function __construct(
        private MunicipalityContext $context,
        private EventAccessPolicy $policy,
        private TerritoryTimezoneResolver $timezones,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function forMunicipality(User $user, string $municipalityId): EventCreationContext
    {
        $municipality = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        $decision = $this->policy->forNewEvent($user, $municipality, $this->clock->now());

        return new EventCreationContext(
            $municipality->name(),
            $decision->isAllowed(),
            $decision->reason,
            $this->timezones->resolve($municipality->inseeCode())?->identifier(),
        );
    }
}
