<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\ActionAvailability;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Read\ZoneBoard;
use App\Event\Application\Read\ZoneGroup;
use App\Event\Application\Read\ZoneView;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\ZoneStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Zones d'un événement pour UNE commune. Le contexte municipal et le lien avec l'événement sont vérifiés par
 * EventDetailReader (refus uniforme). Visibilité : la commune voit TOUJOURS les siennes ; les zones d'une autre commune ne le
 * sont que si cette commune est visible pour elle dans la liste des participations (DM-084 : jamais d'invitation en attente pour
 * la coordination) ET si la politique d'accès l'autorise : l'intersection des deux, jamais l'union. Les zones d'une commune dont l'invitation n'est pas acceptée (INVITED) ne sont jamais listées pour les autres communes (elles restent en base). Une commune simplement
 * invitée ne voit aucune zone (informations minimales, DM-084).
 *
 * Les actions de chaque zone de la commune sont décidées par EventAccessPolicy ; celles qui n'ont pas de sens pour l'état de la
 * zone ne sont pas proposées (« Confirmer » une zone déjà confirmée). Aucune action sur un événement clôturé.
 */
final readonly class EventZoneReader
{
    public function __construct(
        private EventDetailReader $details,
        private MunicipalityContext $context,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private EventAccessPolicy $policy,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function forEvent(User $user, string $municipalityId, string $eventId): ZoneBoard
    {
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        if (EventRelation::Invited === $detail->relation) {
            return new ZoneBoard($detail, [], null, null);
        }
        $viewer = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        $visible = $this->visibleMunicipalityIds($detail);

        $groups = [];
        $ownParticipation = null;
        foreach ($this->participations->findByEvent(Uuid::fromString($detail->eventId)) as $participation) {
            $isOwn = $participation->municipality()->id()->equals($viewer->id());
            if ($isOwn) {
                $ownParticipation = $participation;
            }
            if (!$isOwn && !\in_array($participation->municipality()->id()->toRfc4122(), $visible, true)) {
                continue;
            }
            if (!$isOwn && ParticipationStatus::Invited === $participation->status()) {
                continue; // invitation non acceptée : la commune n'est pas encore participante, ses zones (conservées) ne sont pas listées
            }
            $views = [];
            foreach ($this->zones->findActiveByParticipation($participation->id()) as $zone) {
                if ($this->policy->forZone($user, EventPermission::ViewZone, $zone)->isAllowed()) {
                    $views[] = $this->view($user, $zone, $isOwn, $isOwn && !$detail->status->isClosed());
                }
            }
            if ([] === $views && !$isOwn) {
                continue;
            }
            $groups[] = new ZoneGroup($participation->municipality()->id()->toRfc4122(), $participation->municipality()->name(), $isOwn, $participation->isOrganizer(), $participation->status(), $views);
        }
        usort($groups, static fn (ZoneGroup $a, ZoneGroup $b): int => [!$a->own, !$a->organizer, $a->municipalityName] <=> [!$b->own, !$b->organizer, $b->municipalityName]);

        $create = null;
        if (null !== $ownParticipation && !$detail->status->isClosed()) {
            $decision = $this->policy->forParticipation($user, EventPermission::CreateZone, $ownParticipation);
            $create = new ActionAvailability(EventAction::CreateZone, $decision->isAllowed(), $decision->reason);
        }

        return new ZoneBoard($detail, $groups, $ownParticipation?->id()->toRfc4122(), $create);
    }

    /**
     * Communes autres que la sienne dont les zones peuvent être montrées, selon le niveau de visibilité des participations.
     *
     * @return list<string>
     */
    private function visibleMunicipalityIds(EventDetail $detail): array
    {
        $participants = $detail->participants;

        return array_map(
            static fn (object $row): string => $row->municipalityId,
            [...$participants->details, ...$participants->summaries, ...$participants->history],
        );
    }

    private function view(User $user, EventZone $zone, bool $own, bool $withActions): ZoneView
    {
        $actions = [];
        if ($withActions) {
            $relevance = [
                [EventAction::UpdateZone, EventPermission::ModifyZone, true],
                [EventAction::ConfirmZone, EventPermission::ModifyZone, !$zone->isConfirmed()],
                [EventAction::SuspendZone, EventPermission::ModifyZone, ZoneStatus::Active === $zone->status()],
                [EventAction::ReactivateZone, EventPermission::ModifyZone, ZoneStatus::Suspended === $zone->status()],
                [EventAction::DeleteZone, EventPermission::DeleteZone, true],
            ];
            foreach ($relevance as [$action, $permission, $pertinent]) {
                if ($pertinent) {
                    $decision = $this->policy->forZone($user, $permission, $zone);
                    $actions[] = new ActionAvailability($action, $decision->isAllowed(), $decision->reason);
                }
            }
        }

        return new ZoneView($zone->id()->toRfc4122(), $zone->label(), $zone->type(), $zone->description(), $zone->status(), $zone->confirmedAt(), $zone->validityMode(), $own, $actions);
    }
}
