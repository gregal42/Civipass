<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;

/**
 * Politique d'accès du module Event : rôle + commune + appartenance à l'événement + états métier (DM-057).
 *
 * Principes :
 * - la commune « agissante » n'est JAMAIS fournie par le client : elle se déduit de la ressource (Event → commune
 *   organisatrice ; EventParticipation → commune ; EventZone → participation → commune ; créneau → zone → participation
 *   → commune) et l'utilisateur doit y avoir un membership effectif ; un identifiant connu ne donne aucun droit ;
 * - lecture : membership effectif (commune ACTIVE ou SUSPENDED) ; écriture : commune ACTIVE et rôle autorisé
 *   (matrice unique : EventPermission) ; `superAdmin` ne donne aucun droit communal implicite ;
 * - états : un événement ou une participation clôturés sont figés ; une participation suspendue garde l'édition de
 *   ses zones (R6) ; aucune restriction n'est ajoutée pour un événement suspendu (DM-056, DM-066) ;
 * - droit d'utilisation commercial : exigé uniquement pour CRÉER un événement (DM-076) ; l'expiration ou la
 *   révocation n'affecte jamais la gestion des événements existants (DM-073).
 * Ne contrôle pas les prérequis qui relèvent des services transactionnels (zones prêtes pour l'activation,
 * participations à clôturer au préalable, unicité des invitations).
 */
final readonly class EventAccessPolicy
{
    public function __construct(
        private MembershipAccessPolicy $memberships,
        private MembershipRepository $membershipRepository,
        private EventParticipationRepository $participations,
        private EventCreationPolicy $creation,
    ) {
    }

    public function forNewEvent(User $user, Municipality $municipality, \DateTimeImmutable $at): AccessDecision
    {
        $denied = $this->requireMembership($user, $municipality, EventPermission::CreateEvent);
        if (null !== $denied) {
            return $denied;
        }

        return $this->creation->canCreateEvent($municipality, $at)
            ? AccessDecision::allow()
            : AccessDecision::deny(AccessDenialReason::NoUsageRight);
    }

    public function forEvent(User $user, EventPermission $permission, Event $event): AccessDecision
    {
        $this->assertSubject($permission, PermissionSubject::Event);

        if (EventPermission::ViewEvent === $permission) {
            return $this->canViewEvent($user, $event) ? AccessDecision::allow() : AccessDecision::deny(AccessDenialReason::NoAccess);
        }
        if (EventPermission::ViewParticipationDetails === $permission) {
            return $this->mayViewParticipationDetails($user, $event->organizerMunicipality())
                ? AccessDecision::allow()
                : AccessDecision::deny(AccessDenialReason::NoAccess);
        }
        $denied = $this->requireMembership($user, $event->organizerMunicipality(), $permission);
        if (null !== $denied) {
            return $denied;
        }

        $status = $event->status();
        if ($status->isClosed()) {
            return AccessDecision::deny(AccessDenialReason::EventClosed);
        }

        return match ($permission) {
            EventPermission::UpdateEvent, EventPermission::InviteMunicipality, EventPermission::CloseEvent => AccessDecision::allow(),
            EventPermission::ActivateEvent => $this->stateAllows(EventStatus::Draft === $status),
            EventPermission::SuspendEvent => $this->stateAllows(EventStatus::Active === $status),
            EventPermission::ReactivateEvent => $this->stateAllows(EventStatus::Suspended === $status),
            default => throw new \LogicException('Permission d\'événement inattendue : '.$permission->value),
        };
    }

    /**
     * Visibilité d'une commune AGISSANTE sur les participations d'un événement (DM-084). La commune est celle du
     * contexte municipal de l'utilisateur ; elle n'est jamais prise sur parole : un membership effectif (lecture) y est
     * exigé, et les privilèges ne s'additionnent pas entre communes.
     * - organisatrice : Full pour les rôles de gestion (administrateur, gestionnaire), Coordination pour l'instructeur
     *   (U5 : ni motifs internes, ni invitations en attente d'autres communes) ;
     * - participante acceptée (ACTIVE, SUSPENDED, CLOSED) : Coordination ;
     * - après clôture de l'événement (U6) : History pour la commune ayant effectivement participé (`activated_at`
     *   renseigné), l'invitation jamais acceptée ne voit que la sienne ;
     * - simplement invitée : OwnInvitationOnly ; étrangère ou sans membership effectif : None.
     */
    public function participantListVisibility(User $user, Municipality $viewer, Event $event): ParticipantListVisibility
    {
        $membership = $this->memberships->effectiveMembership($user, $viewer, write: false);
        if (null === $membership) {
            return ParticipantListVisibility::None;
        }
        $closed = $event->status()->isClosed();
        if ($event->isOrganizedBy($viewer)) {
            foreach (EventPermission::ViewParticipationDetails->roles() ?? [] as $role) {
                if ($membership->hasRole($role)) {
                    return ParticipantListVisibility::Full;
                }
            }

            return $closed ? ParticipantListVisibility::History : ParticipantListVisibility::Coordination;
        }
        $own = $this->participations->findByEventAndMunicipality($event->id(), $viewer->id());
        if (null === $own) {
            return ParticipantListVisibility::None;
        }
        if (ParticipationStatus::Invited === $own->status()) {
            return ParticipantListVisibility::OwnInvitationOnly;
        }
        if ($closed) {
            return null !== $own->activatedAt() ? ParticipantListVisibility::History : ParticipantListVisibility::OwnInvitationOnly;
        }

        return ParticipantListVisibility::Coordination;
    }

    /**
     * Audience du bilan statistique de clôture (LOT 05.6.2) pour la commune AGISSANTE : membership effectif (lecture) exigé et rôle de gestion
     * (MUNICIPALITY_ADMIN ou EVENT_MANAGER ; `INSTRUCTOR` seul : aucun accès). Commune organisatrice : bilan global ; commune ayant réellement participé : sa
     * propre ligne ; toute autre : rien. Un événement non clos n'a pas de bilan.
     */
    public function closureStatisticsAudience(User $user, Municipality $viewer, Event $event): ClosureStatisticsAudience
    {
        if (!$event->status()->isClosed()) {
            return ClosureStatisticsAudience::None;
        }
        $membership = $this->memberships->effectiveMembership($user, $viewer, write: false);
        $managing = false;
        foreach (EventPermission::ViewParticipationDetails->roles() ?? [] as $role) {
            $managing = $managing || (null !== $membership && $membership->hasRole($role));
        }
        if (!$managing) {
            return ClosureStatisticsAudience::None;
        }
        if ($event->isOrganizedBy($viewer)) {
            return ClosureStatisticsAudience::Organizer;
        }
        $own = $this->participations->findByEventAndMunicipality($event->id(), $viewer->id());

        return null !== $own && null !== $own->activatedAt() ? ClosureStatisticsAudience::Participant : ClosureStatisticsAudience::None;
    }

    private function mayViewParticipationDetails(User $user, Municipality $organizer): bool
    {
        $membership = $this->memberships->effectiveMembership($user, $organizer, write: false);
        foreach (EventPermission::ViewParticipationDetails->roles() ?? [] as $role) {
            if (null !== $membership && $membership->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function forParticipation(User $user, EventPermission $permission, EventParticipation $participation): AccessDecision
    {
        $this->assertSubject($permission, PermissionSubject::Participation);

        if (EventPermission::ViewParticipation === $permission) {
            // La commune concernée, et la commune organisatrice qui pilote la clôture et voit les états.
            return $this->hasReadAccessTo($user, [$participation->municipality(), $participation->event()->organizerMunicipality()])
                ? AccessDecision::allow()
                : AccessDecision::deny(AccessDenialReason::NoAccess);
        }
        $denied = $this->requireMembership($user, $participation->municipality(), $permission);
        if (null !== $denied) {
            return $denied;
        }

        $eventClosed = $participation->event()->status()->isClosed();
        $status = $participation->status();

        return match ($permission) {
            EventPermission::AcceptInvitation => $eventClosed
                ? AccessDecision::deny(AccessDenialReason::EventClosed)
                : $this->participationStateAllows(ParticipationStatus::Invited === $status),
            EventPermission::SuspendParticipation => $this->participationStateAllows(ParticipationStatus::Active === $status),
            EventPermission::ReactivateParticipation => $eventClosed
                ? AccessDecision::deny(AccessDenialReason::EventClosed)
                : $this->participationStateAllows(ParticipationStatus::Suspended === $status),
            EventPermission::CloseParticipation => $participation->isOrganizer()
                ? AccessDecision::deny(AccessDenialReason::OrganizerParticipationBoundToEvent)
                : $this->participationStateAllows(\in_array($status, [ParticipationStatus::Active, ParticipationStatus::Suspended], true)),
            EventPermission::CreateZone => $this->zoneEditionAllows($participation),
            default => throw new \LogicException('Permission de participation inattendue : '.$permission->value),
        };
    }

    public function forZone(User $user, EventPermission $permission, EventZone $zone): AccessDecision
    {
        $this->assertSubject($permission, PermissionSubject::Zone);
        $participation = $zone->participation();

        if (EventPermission::ViewZone === $permission) {
            if (!$this->canViewZone($user, $zone)) {
                return AccessDecision::deny(AccessDenialReason::NoAccess);
            }

            return $zone->isDeleted() ? AccessDecision::deny(AccessDenialReason::ZoneDeleted) : AccessDecision::allow();
        }
        $denied = $this->requireMembership($user, $participation->municipality(), $permission);
        if (null !== $denied) {
            return $denied;
        }
        if ($zone->isDeleted()) {
            return AccessDecision::deny(AccessDenialReason::ZoneDeleted);
        }

        return $this->zoneEditionAllows($participation);
    }

    public function forSlot(User $user, EventPermission $permission, EventZoneValidity $slot): AccessDecision
    {
        $this->assertSubject($permission, PermissionSubject::ZoneValiditySlot);
        $zone = $slot->zone();
        $participation = $zone->participation();

        $denied = $this->requireMembership($user, $participation->municipality(), $permission);
        if (null !== $denied) {
            return $denied;
        }
        if ($zone->isDeleted()) {
            return AccessDecision::deny(AccessDenialReason::ZoneDeleted);
        }
        if ($slot->isDeleted()) {
            return AccessDecision::deny(AccessDenialReason::SlotDeleted);
        }

        return $this->zoneEditionAllows($participation);
    }

    /**
     * Membership effectif dans la commune propriétaire, en écriture avec un rôle autorisé par la matrice.
     * Refus : NoAccess (aucun lien), MunicipalityNotWritable (commune non ACTIVE), RoleNotAllowed.
     */
    private function requireMembership(User $user, Municipality $municipality, EventPermission $permission): ?AccessDecision
    {
        if (null === $this->memberships->effectiveMembership($user, $municipality, write: false)) {
            return AccessDecision::deny(AccessDenialReason::NoAccess);
        }
        $membership = $this->memberships->effectiveMembership($user, $municipality, write: true);
        if (null === $membership) {
            return AccessDecision::deny(AccessDenialReason::MunicipalityNotWritable);
        }
        foreach ($permission->roles() ?? [] as $role) {
            if ($membership->hasRole($role)) {
                return null;
            }
        }

        return AccessDecision::deny(AccessDenialReason::RoleNotAllowed);
    }

    private function zoneEditionAllows(EventParticipation $participation): AccessDecision
    {
        if ($participation->event()->status()->isClosed()) {
            return AccessDecision::deny(AccessDenialReason::EventClosed);
        }

        return $participation->allowsZoneEdition()
            ? AccessDecision::allow()
            : AccessDecision::deny(AccessDenialReason::ParticipationNotEditable);
    }

    private function stateAllows(bool $allowed): AccessDecision
    {
        return $allowed ? AccessDecision::allow() : AccessDecision::deny(AccessDenialReason::InvalidEventState);
    }

    private function participationStateAllows(bool $allowed): AccessDecision
    {
        return $allowed ? AccessDecision::allow() : AccessDecision::deny(AccessDenialReason::InvalidParticipationState);
    }

    /**
     * Communes pour lesquelles l'utilisateur peut LIRE (membership actif, commune ACTIVE ou SUSPENDED).
     *
     * @return list<Municipality>
     */
    private function readableMunicipalities(User $user): array
    {
        $municipalities = [];
        foreach ($this->membershipRepository->findActiveByUser($user) as $membership) {
            $municipality = $membership->municipality();
            if (null !== $this->memberships->effectiveMembership($user, $municipality, write: false)) {
                $municipalities[] = $municipality;
            }
        }

        return $municipalities;
    }

    /**
     * @param list<Municipality> $targets
     */
    private function hasReadAccessTo(User $user, array $targets): bool
    {
        foreach ($this->readableMunicipalities($user) as $municipality) {
            foreach ($targets as $target) {
                if ($municipality->id()->equals($target->id())) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Commune organisatrice ou commune participante (même invitée) : rien n'est public pour les autres. */
    private function canViewEvent(User $user, Event $event): bool
    {
        foreach ($this->readableMunicipalities($user) as $municipality) {
            if ($event->isOrganizedBy($municipality)
                || null !== $this->participations->findByEventAndMunicipality($event->id(), $municipality->id())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sa propre commune (y compris la zone « Commune entière » proposée à une invitée) ; sinon lecture seule pour une
     * commune ayant accepté de participer à l'événement (DM-007), jamais pour une commune seulement invitée.
     */
    private function canViewZone(User $user, EventZone $zone): bool
    {
        $participation = $zone->participation();
        $eventId = $participation->event()->id();
        foreach ($this->readableMunicipalities($user) as $municipality) {
            if ($municipality->id()->equals($participation->municipality()->id())) {
                return true;
            }
            $own = $this->participations->findByEventAndMunicipality($eventId, $municipality->id());
            if (null !== $own && ParticipationStatus::Invited !== $own->status()) {
                return true;
            }
        }

        return false;
    }

    private function assertSubject(EventPermission $permission, PermissionSubject $expected): void
    {
        if ($permission->subject() !== $expected) {
            throw new \LogicException(sprintf('La permission %s ne s\'applique pas à cette ressource.', $permission->value));
        }
    }
}
