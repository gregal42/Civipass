<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\ParticipantList;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Consultation des participations d'un événement par une commune (DM-084) : la visibilité est décidée par la
 * politique d'accès, PUIS seule la requête correspondant à ce niveau est exécutée. Une commune ne reçoit donc
 * jamais une projection plus riche que son niveau, et les données internes ne sont même pas lues pour elle.
 * Événement inexistant, identifiant invalide et commune sans visibilité : même refus « Ressource inaccessible. ».
 */
final readonly class EventParticipantReader
{
    public function __construct(
        private MunicipalityContext $context,
        private EventAccessPolicy $policy,
        private EventRepository $events,
        private EventReadModel $read,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function forEvent(User $user, string $municipalityId, string $eventId): ParticipantList
    {
        $viewer = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $visibility = $this->policy->participantListVisibility($user, $viewer, $event);

        return match ($visibility) {
            ParticipantListVisibility::Full => new ParticipantList($visibility, details: $this->read->participationsOf($event->id())),
            ParticipantListVisibility::Coordination => new ParticipantList($visibility, summaries: $this->read->coordinationOf($event->id())),
            ParticipantListVisibility::History => new ParticipantList($visibility, history: $this->read->historyOf($event->id())),
            ParticipantListVisibility::OwnInvitationOnly => new ParticipantList($visibility, invitation: $this->read->invitationOf($event->id(), $viewer->id())),
            ParticipantListVisibility::None => throw new OperationNotAllowed('Ressource inaccessible.'),
        };
    }
}
