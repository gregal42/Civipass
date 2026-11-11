<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\ClosureStatisticsView;
use App\Event\Domain\ClosureStatisticsPublication;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Bilan statistique de clôture d'un événement pour UNE commune (LOT 05.6.2, DM-120, P4 à P6). Publication CALCULÉE À LA LECTURE à partir des compteurs internes
 * exacts et du seuil de confidentialité courant (provisoire, paramètre `CLOSURE_STATISTICS_MIN_COUNT`) : aucun recalcul, aucune valeur exacte exposée.
 *
 * Permissions : commune de l'écran = identifiant NON FIABLE, relu par `MunicipalityContext` ; audience décidée par `EventAccessPolicy`. Renvoie `null` pour tout
 * lecteur non habilité, événement non clos ou événement clos avant M1 (compteurs absents : « bilan non disponible », jamais de recalcul).
 * Ventilation par commune : uniquement avec la coordination de l'organisatrice (visibilité « Full » des participations, rôles de gestion) ; une désignation
 * explicite plus fine relève de l'archive (05.6.3).
 */
final readonly class ClosureStatisticsReader
{
    public function __construct(
        private MunicipalityContext $context,
        private EventRepository $events,
        private EventAccessPolicy $policy,
        private MunicipalityRepository $municipalities,
        #[Autowire('%env(int:CLOSURE_STATISTICS_MIN_COUNT)%')] private int $threshold,
    ) {
    }

    public function forViewer(User $user, string $municipalityId, string $eventId): ?ClosureStatisticsView
    {
        try {
            $viewer = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        } catch (OperationNotAllowed) {
            return null;
        }
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event) {
            return null;
        }
        $audience = $this->policy->closureStatisticsAudience($user, $viewer, $event);
        $statistics = ClosureStatisticsAudience::None === $audience ? null : $event->closureStatistics();
        if (null === $statistics) {
            return null;
        }
        $threshold = ClosureStatisticsPublication::threshold($this->threshold);
        $own = ClosureStatisticsPublication::forMunicipality($statistics, $viewer->id()->toRfc4122(), $threshold);
        if (ClosureStatisticsAudience::Participant === $audience) {
            return new ClosureStatisticsView(null, $own, [], false);
        }
        $breakdown = ParticipantListVisibility::Full === $this->policy->participantListVisibility($user, $viewer, $event);
        $global = ClosureStatisticsPublication::forOrganizer($statistics, $threshold, $breakdown);
        $names = [];
        foreach ($global->communes ?? [] as $line) {
            $names[$line['municipalityId']] = $this->municipalities->find(Uuid::fromString($line['municipalityId']))?->name() ?? 'Commune';
        }

        return new ClosureStatisticsView($global, $own, $names, 1 === \count($statistics->municipalities));
    }
}
