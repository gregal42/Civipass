<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\ClosureInvitationRevoker;
use App\Event\Application\ClosureNotificationNeutralizer;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventPermission;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\ClosedParticipation;
use App\Event\Application\Result\EventClosureResult;
use App\Event\Application\Result\EventStateResult;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Time\EventTimezone;
use App\Identity\Domain\User;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle de vie d'un événement : modification, activation, suspension, réactivation, clôture globale (sous-étape 5.3).
 *
 * Les invariants restent dans les entités (transitions, verrouillages après activation, dernière suspension conservée) :
 * ce service orchestre. Gabarit : pré-autorisation hors transaction → verrou EXCLUSIF de l'événement en première
 * instruction (puis, pour la clôture, de toutes ses participations par identifiant croissant) → politique rappelée sous
 * verrou → préconditions relues sous verrou → validation avant toute modification → audit dans la même transaction.
 * Le compteur global n'intervient jamais ici.
 */
final readonly class EventLifecycleService
{
    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private EventResourceResolver $resolver,
        private EventAccessPolicy $policy,
        private EventLocker $locker,
        private EventTypeRepository $types,
        private EventZoneRepository $zones,
        private EventAuditRecorder $audit,
        private ClosureNotificationNeutralizer $notifications,
        private ClosureInvitationRevoker $invitations,
    ) {
    }

    /**
     * Modifie les champs autorisés selon l'état (DM-071, DM-082). Un changement nul n'écrit rien et n'audite rien. Toutes les
     * modifications sont validées sur une copie AVANT la première écriture : aucune application partielle.
     *
     * @throws OperationNotAllowed droit insuffisant, événement inaccessible ou clôturé
     * @throws DomainRuleViolation champ verrouillé après activation, fin dans le passé, valeurs invalides
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function update(User $actor, string $eventId, UpdateEventCommand $command): EventStateResult
    {
        $known = $this->resolver->event($actor, $eventId, EventPermission::UpdateEvent);
        $type = null === $command->typeCode ? null : ($this->types->findByCode($command->typeCode) ?? throw new DomainRuleViolation('Type d\'événement inconnu.'));
        $timezone = null === $command->timezone ? null : EventTimezone::fromIdentifier($command->timezone);

        return $this->transactions->run(function () use ($actor, $known, $command, $type, $timezone): EventStateResult {
            $event = $this->locker->exclusiveEvent($known->id());
            $this->policy->forEvent($actor, EventPermission::UpdateEvent, $event)->orThrow();
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            $changed = $this->changedFields($event, $command, $type, $timezone);
            if ([] === $changed) {
                return $this->result($event, []);
            }
            $this->apply(clone $event, $command, $type, $timezone, $changed, $now); // validation à blanc
            $previous = EventAuditRecorder::eventState($event);
            $this->apply($event, $command, $type, $timezone, $changed, $now);

            $this->audit->record(
                AuditAction::EventUpdated,
                AuditSubjectType::Event,
                $event->id(),
                $event->organizerMunicipality()->id(),
                $actor,
                $now,
                $previous,
                [...EventAuditRecorder::eventState($event), 'changed' => $changed],
            );

            return $this->result($event, $changed);
        });
    }

    /**
     * DRAFT → ACTIVE. Prérequis relu SOUS verrou (DM-059, R7) : au moins une zone confirmée, active, non supprimée,
     * d'une participation ACTIVE, avec une validité configurée (créneau non supprimé si périodes spécifiques).
     *
     * @throws DomainRuleViolation aucune zone prête pour l'activation
     */
    public function activate(User $actor, string $eventId): EventStateResult
    {
        return $this->transition($actor, $eventId, EventPermission::ActivateEvent, AuditAction::EventActivated, function (Event $event, \DateTimeImmutable $now): void {
            if (!$this->zones->existsActivationReadyZone($event->id())) {
                throw new DomainRuleViolation('Aucune zone n\'est prête pour l\'activation : confirmez au moins une zone active dont la validité est configurée.');
            }
            $event->activate($now);
        });
    }

    /**
     * ACTIVE → SUSPENDED, motif obligatoire. Les participations ne sont pas suspendues automatiquement (aucune décision ne le prévoit).
     */
    public function suspend(User $actor, string $eventId, string $reason): EventStateResult
    {
        return $this->transition($actor, $eventId, EventPermission::SuspendEvent, AuditAction::EventSuspended, static function (Event $event, \DateTimeImmutable $now) use ($reason): void {
            $event->suspend($reason, $now);
        }, ['reasonProvided' => true]);
    }

    /** SUSPENDED → ACTIVE ; la dernière suspension (motif, date) est conservée (DM-081). */
    public function reactivate(User $actor, string $eventId): EventStateResult
    {
        return $this->transition($actor, $eventId, EventPermission::ReactivateEvent, AuditAction::EventReactivated, static function (Event $event, \DateTimeImmutable $now): void {
            $event->reactivate($now);
        });
    }

    /**
     * Clôture globale (DM-092) depuis DRAFT, ACTIVE ou SUSPENDED : l'événement ET toutes ses participations ouvertes
     * (INVITED, ACTIVE, SUSPENDED) sont clôturés dans la même transaction, sans action préalable des communes. Un audit par
     * objet effectivement modifié, un `batch_id` commun ; échec ⇒ rollback intégral. Irréversible.
     *
     * @throws OperationNotAllowed droit insuffisant, événement inaccessible ou déjà clôturé
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function close(User $actor, string $eventId): EventClosureResult
    {
        $known = $this->resolver->event($actor, $eventId, EventPermission::CloseEvent);

        return $this->transactions->run(function () use ($actor, $known): EventClosureResult {
            // Événement (X) puis toutes les participations (X, identifiant croissant) : jamais l'inverse.
            $event = $this->locker->exclusiveEvent($known->id());
            $participations = $this->locker->allParticipations($event);
            $this->policy->forEvent($actor, EventPermission::CloseEvent, $event)->orThrow();
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            $batch = Uuid::v7();
            $previousStatus = $event->status();
            $previousState = EventAuditRecorder::eventState($event);
            $event->close($now);

            $closed = [];
            $participationAudits = [];
            foreach ($participations as $participation) {
                if (ParticipationStatus::Closed === $participation->status()) {
                    continue; // aucune mutation, aucun audit
                }
                $before = EventAuditRecorder::participationState($participation);
                $beforeStatus = $participation->status();
                $participation->closeWithEvent($now);
                $closed[] = new ClosedParticipation($participation->id()->toRfc4122(), $participation->municipality()->id()->toRfc4122(), $beforeStatus);
                $participationAudits[] = [$participation, $before];
            }

            // Effets de la clôture sur les accès (LOT 05.6.2), dans la même transaction et APRÈS les verrous événement → participations (lignes d'outbox en dernier) :
            // notifications en attente supprimées (prioritaires comprises), secrets et liens jamais remis retirés, invitations non consommées révoquées.
            $notifications = $this->notifications->neutralize($event->id(), $now);
            $revokedInvitations = $this->invitations->revokeUnconsumed($event->id(), $now);

            $this->audit->record(
                AuditAction::EventClosed,
                AuditSubjectType::Event,
                $event->id(),
                $event->organizerMunicipality()->id(),
                $actor,
                $now,
                $previousState,
                [...EventAuditRecorder::eventState($event), 'cause' => EventStatus::Draft === $previousStatus ? 'FROM_DRAFT' : 'NORMAL', 'closedParticipations' => \count($closed), 'cancelledNotifications' => $notifications['cancelled'], 'removedSecrets' => $notifications['secrets'], 'revokedLinks' => $notifications['links'], 'revokedInvitations' => $revokedInvitations],
                $batch,
            );
            foreach ($participationAudits as [$participation, $before]) {
                $this->audit->record(
                    AuditAction::ParticipationClosed,
                    AuditSubjectType::EventParticipation,
                    $participation->id(),
                    $participation->municipality()->id(),
                    $actor,
                    $now,
                    $before,
                    [...EventAuditRecorder::participationState($participation), 'cause' => 'EVENT_CLOSED'],
                    $batch,
                );
            }

            return new EventClosureResult($event->id()->toRfc4122(), $previousStatus, $closed, $batch->toRfc4122());
        });
    }

    /**
     * @param \Closure(Event, \DateTimeImmutable): void $mutate garde propre à l'opération puis mutation du domaine
     * @param array<string, mixed>                      $extra  champs structurés ajoutés à l'état audité (jamais de texte libre)
     */
    private function transition(User $actor, string $eventId, EventPermission $permission, AuditAction $action, \Closure $mutate, array $extra = []): EventStateResult
    {
        $known = $this->resolver->event($actor, $eventId, $permission);

        return $this->transactions->run(function () use ($actor, $known, $permission, $action, $mutate, $extra): EventStateResult {
            $event = $this->locker->exclusiveEvent($known->id());
            $this->policy->forEvent($actor, $permission, $event)->orThrow();
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            $previous = EventAuditRecorder::eventState($event);
            $mutate($event, $now);

            $this->audit->record($action, AuditSubjectType::Event, $event->id(), $event->organizerMunicipality()->id(), $actor, $now, $previous, [...EventAuditRecorder::eventState($event), ...$extra]);

            return $this->result($event, []);
        });
    }

    /**
     * @return list<string>
     */
    private function changedFields(Event $event, UpdateEventCommand $c, ?\App\Event\Domain\EventType $type, ?EventTimezone $timezone): array
    {
        $changed = [];
        if (null !== $c->name && trim($c->name) !== $event->name()) {
            $changed[] = 'name';
        }
        if (null !== $c->description && self::blankToNull($c->description) !== $event->description()) {
            $changed[] = 'description';
        }
        if (null !== $c->operationalInstructions && self::blankToNull($c->operationalInstructions) !== $event->operationalInstructions()) {
            $changed[] = 'operationalInstructions';
        }
        if (null !== $c->startsAt && $c->startsAt != $event->startsAt()) {
            $changed[] = 'startsAt';
        }
        if ($c->clearEndsAt ? null !== $event->endsAt() : (null !== $c->endsAt && $c->endsAt != $event->endsAt())) {
            $changed[] = 'endsAt';
        }
        if (null !== $type && !$type->id()->equals($event->type()->id())) {
            $changed[] = 'type';
        }
        if (null !== $timezone && !$timezone->equals($event->timezone())) {
            $changed[] = 'timezone';
        }

        return $changed;
    }

    /**
     * @param list<string> $changed
     */
    private function apply(Event $event, UpdateEventCommand $c, ?\App\Event\Domain\EventType $type, ?EventTimezone $timezone, array $changed, \DateTimeImmutable $now): void
    {
        if (\in_array('type', $changed, true) && null !== $type) {
            $event->changeType($type, $now);
        }
        if (\in_array('timezone', $changed, true) && null !== $timezone) {
            $event->changeTimezone($timezone, $now);
        }
        if (\in_array('name', $changed, true) && null !== $c->name) {
            $event->rename($c->name, $now);
        }
        if (\in_array('description', $changed, true)) {
            $event->describe($c->description, $now);
        }
        if (\in_array('operationalInstructions', $changed, true)) {
            $event->changeOperationalInstructions($c->operationalInstructions, $now);
        }
        if (\in_array('startsAt', $changed, true) || \in_array('endsAt', $changed, true)) {
            $event->reschedule($c->startsAt ?? $event->startsAt(), $c->clearEndsAt ? null : ($c->endsAt ?? $event->endsAt()), $now);
        }
    }

    /**
     * @param list<string> $changed
     */
    private function result(Event $event, array $changed): EventStateResult
    {
        return new EventStateResult($event->id()->toRfc4122(), $event->publicReference()->value, $event->status(), $changed);
    }

    private static function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
