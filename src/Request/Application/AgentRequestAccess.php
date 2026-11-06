<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Read\RequestEventOption;
use App\Request\Application\Result\AgentRequestScope;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Qui peut saisir une demande pour un tiers, et où. La commune de l'URL et l'événement sont des identifiants NON FIABLES :
 * l'adhésion effective de l'utilisateur à CETTE commune (écriture : commune active) doit porter MUNICIPALITY_ADMIN, INSTRUCTOR ou EVENT_MANAGER (aucun n'ouvre la consultation des demandes), la
 * commune doit participer à l'événement, et participation comme événement doivent être opérationnels. Refus uniforme (404) pour
 * une commune, un événement ou une participation inexistants ; 403 quand l'agent voit la ressource sans pouvoir y déposer.
 * Le service de dépôt revérifie tout cela sous verrou : ce contrôle protège les écrans, il ne remplace pas celui du dépôt.
 */
final readonly class AgentRequestAccess
{
    public function __construct(
        private MunicipalityContext $context,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private MunicipalRequestReadModel $read,
    ) {
    }

    public static function mayEnter(MunicipalityMembership $membership): bool
    {
        return $membership->hasRole(MunicipalityRole::MunicipalityAdmin)
            || $membership->hasRole(MunicipalityRole::Instructor)
            || $membership->hasRole(MunicipalityRole::EventManager);
    }

    /**
     * @param bool $requireOperational faux pour relire une confirmation déjà obtenue (l'événement a pu se clore depuis)
     *
     * @throws OperationNotAllowed
     * @throws AgentRequestDenied
     */
    public function authorize(User $user, string $municipalityId, string $eventId, bool $requireOperational = true): AgentRequestScope
    {
        $municipality = $this->membership($user, $municipalityId)->municipality();
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        $participation = null === $event ? null : $this->participations->findByEventAndMunicipality($event->id(), $municipality->id());
        if (null === $event || null === $participation) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if ($requireOperational && !$participation->isOperational()) {
            throw new AgentRequestDenied('Participation ou événement non opérationnel.');
        }

        return new AgentRequestScope($municipality, $event);
    }

    /**
     * Événements pour lesquels la commune peut déposer (événement actif, participation active), récents d'abord.
     *
     * @return list<RequestEventOption>
     *
     * @throws OperationNotAllowed
     * @throws AgentRequestDenied
     */
    public function eligibleEvents(User $user, string $municipalityId): array
    {
        $municipality = $this->membership($user, $municipalityId)->municipality();

        return array_values(array_filter(
            $this->read->eventsOf($municipality->id()),
            static fn (RequestEventOption $e): bool => EventStatus::Active === $e->status && ParticipationStatus::Active === $e->participationStatus,
        ));
    }

    private function membership(User $user, string $municipalityId): MunicipalityMembership
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: true);
        if (!self::mayEnter($membership)) {
            throw new AgentRequestDenied('Rôle MUNICIPALITY_ADMIN, INSTRUCTOR ou EVENT_MANAGER requis.');
        }

        return $membership;
    }
}
