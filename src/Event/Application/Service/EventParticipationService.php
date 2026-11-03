<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventPermission;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\InvitationResult;
use App\Event\Application\Result\ParticipationStatusResult;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Participations d'un événement : invitation et réexpédition (sous-étape 5.2). L'acceptation et les transitions
 * suivront en 5.3.
 *
 * Gabarit : pré-autorisation hors transaction → verrou exclusif de l'événement en première instruction → rappel de la
 * politique sous verrou → préconditions relues sous verrou → validation → modification → audit.
 */
final readonly class EventParticipationService
{
    private const UNIQUE_PARTICIPATION = 'uniq_event_participation_event_municipality';

    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private EventResourceResolver $resolver,
        private EventAccessPolicy $policy,
        private EventCreationPolicy $creationPolicy,
        private EventLocker $locker,
        private MunicipalityRepository $municipalities,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private EventAuditRecorder $audit,
    ) {
    }

    /**
     * Invite une commune, désignée par son identifiant stable (D4). Une commune non abonnée reste invitable : seul son
     * statut administratif compte ; l'invitation ne crée ni utilisateur, ni adhésion, ni abonnement, ni droit commercial.
     *
     * @throws OperationNotAllowed droit insuffisant, événement inaccessible ou clôturé, commune indisponible, déjà invitée,
     *                             participation clôturée (D8)
     * @throws OperationConflict   invitation concurrente de la même commune ou verrou non obtenu
     */
    public function invite(User $actor, InviteMunicipalityCommand $command): InvitationResult
    {
        $now = $this->clock->now();
        $event = $this->resolver->event($actor, $command->eventId, EventPermission::InviteMunicipality);
        $target = $this->eligibleMunicipality($command->municipalityId);

        return $this->transactions->run(function () use ($actor, $command, $event, $target, $now): InvitationResult {
            $locked = $this->locker->exclusiveEvent($event->id());
            $this->policy->forEvent($actor, EventPermission::InviteMunicipality, $locked)->orThrow();

            // Relu SOUS verrou : une invitation concurrente validée avant nous est visible ; la contrainte d'unicité reste le filet.
            $existing = $this->participations->findByEventAndMunicipality($locked->id(), $target->id());
            if (null !== $existing) {
                throw new OperationNotAllowed(ParticipationStatus::Closed === $existing->status() ? 'Cette commune ne peut plus être invitée à cet événement : sa participation est clôturée.' : 'Cette commune est déjà invitée à cet événement ou y participe déjà.');
            }
            if (!$this->creationPolicy->canJoinNewEvent($target)) {
                throw new OperationNotAllowed('Commune introuvable ou indisponible.');
            }

            // Toutes les validations du domaine précèdent la première écriture.
            $participation = EventParticipation::invite($locked, $target, $actor, $command->message, $now);
            $zone = EventZone::proposeWholeMunicipality($participation, $actor, $now);

            $this->participations->add($participation);
            $this->zones->add($zone);

            $batch = Uuid::v7();
            $this->audit->record(AuditAction::ParticipationInvited, AuditSubjectType::EventParticipation, $participation->id(), $target->id(), $actor, $now, null, EventAuditRecorder::participationState($participation), $batch);
            $this->audit->record(AuditAction::ZoneCreated, AuditSubjectType::EventZone, $zone->id(), $target->id(), $actor, $now, null, EventAuditRecorder::zoneState($zone, 'AUTO_PROPOSAL'), $batch);

            return new InvitationResult($participation->id()->toRfc4122(), $target->id()->toRfc4122(), $participation->status(), $participation->lastInvitationSentAt() ?? $now, $zone->id()->toRfc4122());
        }, [self::UNIQUE_PARTICIPATION]);
    }

    /**
     * Réexpédie une invitation toujours en attente : même participation, date d'envoi actualisée, audit. Enregistre la
     * DEMANDE d'envoi : l'envoi effectif (e-mail, SMS) n'est pas dans le périmètre technique validé et n'est pas simulé.
     *
     * @throws OperationNotAllowed droit insuffisant, ressource inaccessible, événement clôturé
     * @throws DomainRuleViolation la participation n'est plus en attente
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function resendInvitation(User $actor, string $participationId): InvitationResult
    {
        $now = $this->clock->now();
        $known = Uuid::isValid($participationId) ? $this->participations->find(Uuid::fromString($participationId)) : null;
        if (null === $known) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $this->policy->forEvent($actor, EventPermission::InviteMunicipality, $known->event())->orThrow();

        return $this->transactions->run(function () use ($actor, $known, $now): InvitationResult {
            $event = $this->locker->exclusiveEvent($known->event()->id());
            $participation = $this->locker->participation($known->id(), $event);
            $this->policy->forEvent($actor, EventPermission::InviteMunicipality, $event)->orThrow();

            $previous = $participation->lastInvitationSentAt();
            $participation->resendInvitation($now); // refuse (sans écrire) si l'invitation n'est plus en attente

            $this->audit->record(
                AuditAction::ParticipationInvitationResent,
                AuditSubjectType::EventParticipation,
                $participation->id(),
                $participation->municipality()->id(),
                $actor,
                $now,
                ['lastInvitationSentAt' => $previous?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')],
                ['lastInvitationSentAt' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'status' => $participation->status()->value],
            );

            return new InvitationResult($participation->id()->toRfc4122(), $participation->municipality()->id()->toRfc4122(), $participation->status(), $now);
        });
    }

    /**
     * Accepte une invitation. Réservée à l'administrateur de la commune invitée (R2) ; AUCUN abonnement, TRIAL ni accès
     * commercial n'est exigé (accès gratuit limité à l'événement, D4) et rien de commercial n'est créé. Jamais après la
     * clôture : l'état de l'événement est relu sous verrou partagé.
     *
     * @throws OperationNotAllowed droit insuffisant, ressource inaccessible, événement clôturé, pas en attente
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function accept(User $actor, string $participationId): ParticipationStatusResult
    {
        return $this->transition($actor, $participationId, EventPermission::AcceptInvitation, AuditAction::ParticipationAccepted, static function (EventParticipation $p, \DateTimeImmutable $now): void {
            $p->accept($now);
        });
    }

    /** ACTIVE → SUSPENDED, motif obligatoire ; la participation organisatrice peut aussi être suspendue (jamais clôturée seule). */
    public function suspend(User $actor, string $participationId, string $reason): ParticipationStatusResult
    {
        return $this->transition($actor, $participationId, EventPermission::SuspendParticipation, AuditAction::ParticipationSuspended, static function (EventParticipation $p, \DateTimeImmutable $now) use ($reason): void {
            $p->suspend($reason, $now);
        }, ['reasonProvided' => true]);
    }

    /** SUSPENDED → ACTIVE tant que l'événement n'est pas clôturé ; la dernière suspension (motif, date) est conservée (DM-081). */
    public function reactivate(User $actor, string $participationId): ParticipationStatusResult
    {
        return $this->transition($actor, $participationId, EventPermission::ReactivateParticipation, AuditAction::ParticipationReactivated, static function (EventParticipation $p, \DateTimeImmutable $now): void {
            $p->reactivate($now);
        });
    }

    /**
     * Clôture de SA participation (ACTIVE ou SUSPENDED), définitive : jamais réactivée ni réinvitée. Refusée pour la
     * participation organisatrice (clôturée avec l'événement) et pour une invitation en attente.
     */
    public function close(User $actor, string $participationId): ParticipationStatusResult
    {
        return $this->transition($actor, $participationId, EventPermission::CloseParticipation, AuditAction::ParticipationClosed, static function (EventParticipation $p, \DateTimeImmutable $now): void {
            $p->close($now);
        }, ['cause' => 'PARTICIPANT']);
    }

    /**
     * Transition d'une participation : verrou PARTAGÉ de l'événement (il ne doit pas être clôturé, activé ou modifié en
     * même temps, sans que deux communes ne s'excluent) puis verrou exclusif de la participation — ordre événement →
     * participation, jamais d'upgrade implicite.
     *
     * @param \Closure(EventParticipation, \DateTimeImmutable): void $mutate
     * @param array<string, mixed>                                   $extra
     */
    private function transition(User $actor, string $participationId, EventPermission $permission, AuditAction $action, \Closure $mutate, array $extra = []): ParticipationStatusResult
    {
        $now = $this->clock->now();
        $known = $this->resolver->participation($actor, $participationId, $permission);

        return $this->transactions->run(function () use ($actor, $known, $permission, $action, $mutate, $extra, $now): ParticipationStatusResult {
            $event = $this->locker->sharedEvent($known->event()->id());
            $participation = $this->locker->participation($known->id(), $event);
            $this->policy->forParticipation($actor, $permission, $participation)->orThrow();

            $previous = EventAuditRecorder::participationState($participation);
            $mutate($participation, $now);

            $this->audit->record($action, AuditSubjectType::EventParticipation, $participation->id(), $participation->municipality()->id(), $actor, $now, $previous, [...EventAuditRecorder::participationState($participation), ...$extra]);

            return new ParticipationStatusResult($participation->id()->toRfc4122(), $participation->municipality()->id()->toRfc4122(), $participation->status());
        });
    }

    /**
     * Commune désignée par identifiant : inexistante, invalide ou indisponible produisent le même refus (aucune
     * information sur le référentiel). Aucun critère d'abonnement : seul le statut administratif compte.
     */
    private function eligibleMunicipality(string $municipalityId): Municipality
    {
        $municipality = Uuid::isValid($municipalityId) ? $this->municipalities->find(Uuid::fromString($municipalityId)) : null;
        if (null === $municipality || !$this->creationPolicy->canJoinNewEvent($municipality)) {
            throw new OperationNotAllowed('Commune introuvable ou indisponible.');
        }

        return $municipality;
    }
}
