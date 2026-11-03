<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\ActionAvailability;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventLifecycleDates;
use App\Event\Application\Read\EventRelation;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Fiche d'un événement pour UNE commune. Le contexte municipal est un identifiant non fiable : il exige un membership
 * effectif (MunicipalityContext) ET un lien de la commune avec l'événement (organisatrice, participante ou invitée) ; les
 * participations sont filtrées par EventParticipantReader (DM-084). Sinon : le même refus que pour une ressource inexistante.
 *
 * Les actions sont décidées par EventAccessPolicy, jamais déduites du rôle ici. Seules les actions PERTINENTES pour
 * l'état de l'événement sont retournées (ex. « Activer » n'a pas de sens pour un événement actif) ; un refus de rôle ou de
 * statut de la commune est retourné avec son motif pour que l'écran l'explique.
 */
final readonly class EventDetailReader
{
    public function __construct(
        private MunicipalityContext $context,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private EventParticipantReader $participants,
        private EventAccessPolicy $policy,
        private EventZoneRepository $zones,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function forEvent(User $user, string $municipalityId, string $eventId): EventDetail
    {
        // Lève le refus uniforme si la commune est inaccessible, l'événement inconnu ou sans lien avec la commune.
        $participantList = $this->participants->forEvent($user, $municipalityId, $eventId);
        $viewer = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        $event = $this->events->find(Uuid::fromString($eventId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');

        $own = $this->participations->findByEventAndMunicipality($event->id(), $viewer->id());
        $relation = match (true) {
            $event->isOrganizedBy($viewer) => EventRelation::Organizer,
            null !== $own && ParticipationStatus::Invited === $own->status() => EventRelation::Invited,
            null !== $own => EventRelation::Participant,
            default => throw new OperationNotAllowed('Ressource inaccessible.'),
        };
        $invitedOnly = EventRelation::Invited === $relation;

        return new EventDetail(
            $event->id()->toRfc4122(),
            $event->publicReference()->value,
            $event->name(),
            $event->type()->code(),
            $event->type()->label(),
            $event->status(),
            $event->organizerMunicipality()->id()->toRfc4122(),
            $event->organizerMunicipality()->name(),
            $event->startsAt(),
            $event->endsAt(),
            $event->timezone()->identifier(),
            $relation,
            EventRelation::Organizer === $relation ? null : $own?->status(),
            $invitedOnly ? null : $event->description(),
            $invitedOnly ? null : new EventLifecycleDates($event->createdAt(), $event->activatedAt(), $event->suspendedAt(), $event->closedAt()),
            $participantList,
            $this->actions($user, $event, $relation, $own),
            EventRelation::Organizer === $relation && EventStatus::Draft === $event->status() ? $this->zones->existsActivationReadyZone($event->id()) : null,
        );
    }

    /**
     * @return list<ActionAvailability>
     */
    private function actions(User $user, Event $event, EventRelation $relation, ?EventParticipation $own): array
    {
        if ($event->status()->isClosed()) {
            return [];
        }
        if (EventRelation::Organizer === $relation) {
            $pertinent = match ($event->status()) {
                EventStatus::Draft => [EventAction::Update, EventAction::Invite, EventAction::Activate, EventAction::Close],
                EventStatus::Active => [EventAction::Update, EventAction::Invite, EventAction::Suspend, EventAction::Close],
                EventStatus::Suspended => [EventAction::Update, EventAction::Invite, EventAction::Reactivate, EventAction::Close],
                EventStatus::Closed => [],
            };
            $permissions = [
                EventAction::Update->value => EventPermission::UpdateEvent,
                EventAction::Invite->value => EventPermission::InviteMunicipality,
                EventAction::Activate->value => EventPermission::ActivateEvent,
                EventAction::Suspend->value => EventPermission::SuspendEvent,
                EventAction::Reactivate->value => EventPermission::ReactivateEvent,
                EventAction::Close->value => EventPermission::CloseEvent,
            ];

            return array_map(fn (EventAction $a): ActionAvailability => $this->availability($a, $this->policy->forEvent($user, $permissions[$a->value], $event)), $pertinent);
        }
        if (null === $own) {
            return [];
        }
        $pertinent = match ($own->status()) {
            ParticipationStatus::Invited => [[EventAction::AcceptInvitation, EventPermission::AcceptInvitation]],
            ParticipationStatus::Active => [[EventAction::SuspendParticipation, EventPermission::SuspendParticipation], [EventAction::CloseParticipation, EventPermission::CloseParticipation]],
            ParticipationStatus::Suspended => [[EventAction::ReactivateParticipation, EventPermission::ReactivateParticipation], [EventAction::CloseParticipation, EventPermission::CloseParticipation]],
            ParticipationStatus::Closed => [],
        };
        $result = [];
        foreach ($pertinent as [$action, $permission]) {
            $result[] = $this->availability($action, $this->policy->forParticipation($user, $permission, $own));
        }

        return $result;
    }

    private function availability(EventAction $action, AccessDecision $decision): ActionAvailability
    {
        return new ActionAvailability($action, $decision->isAllowed(), $decision->reason);
    }
}
