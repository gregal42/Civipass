<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\PublicReference;
use App\Event\Domain\PublicReferenceGenerator;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\TerritoryTimezoneResolver;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Création atomique d'un événement : référence globale + événement `DRAFT` + participation organisatrice `ACTIVE`
 * (DM-053) + zone « Commune entière — [nom] » proposée, non confirmée (DM-058) + audit, dans UNE transaction.
 *
 * Aucune ressource existante à verrouiller : l'événement n'existe pas encore. Le seul verrou est la ligne du compteur
 * global, prise EN DERNIER (D2), après validation complète des préconditions ; elle est libérée avec la transaction.
 * Un échec à n'importe quelle étape annule tout : compteur, événement, participation, zone et audits.
 */
final readonly class EventCreationService
{
    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private MunicipalityContext $context,
        private EventAccessPolicy $policy,
        private EventTypeRepository $types,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private PublicReferenceGenerator $references,
        private TerritoryTimezoneResolver $timezones,
        private EventAuditRecorder $audit,
    ) {
    }

    /**
     * @throws OperationNotAllowed commune inaccessible, rôle, statut administratif, aucun droit d'utilisation
     * @throws DomainRuleViolation type inconnu, fuseau non déterminable ou invalide, nom ou dates invalides
     * @throws OperationConflict   conflit technique concurrent
     */
    public function create(User $actor, string $municipalityId, CreateEventCommand $command): CreatedEvent
    {
        $now = $this->clock->now();
        // Contexte municipal non fiable : membership effectif exigé ; refus uniforme sinon.
        $municipality = $this->context->requireMembership($actor, $municipalityId)->municipality();
        $this->policy->forNewEvent($actor, $municipality, $now)->orThrow();

        $type = $this->types->findByCode($command->typeCode) ?? throw new DomainRuleViolation('Type d\'événement inconnu.');
        $timezone = $this->resolveTimezone($municipality, $command->timezone);
        // Validation à blanc (nom, dates, type actif) AVANT de toucher au compteur : le verrou n'est pris qu'ensuite.
        $this->newEvent(PublicReference::fromCounter(1, $now), $municipality, $command, $timezone, $actor, $now, $type);

        return $this->transactions->run(function () use ($actor, $municipality, $command, $timezone, $type, $now): CreatedEvent {
            // Droits et statut relus au moment de l'écriture (ils ont pu changer depuis la pré-autorisation).
            $this->policy->forNewEvent($actor, $municipality, $now)->orThrow();

            $event = $this->newEvent($this->references->next($now), $municipality, $command, $timezone, $actor, $now, $type);
            $participation = EventParticipation::forOrganizer($event, $now);
            $zone = EventZone::proposeWholeMunicipality($participation, $actor, $now);

            $this->events->add($event);
            $this->participations->add($participation);
            $this->zones->add($zone);

            $batch = Uuid::v7();
            $this->audit->record(AuditAction::EventCreated, AuditSubjectType::Event, $event->id(), $municipality->id(), $actor, $now, null, EventAuditRecorder::eventState($event), $batch);
            $this->audit->record(AuditAction::ParticipationCreated, AuditSubjectType::EventParticipation, $participation->id(), $municipality->id(), $actor, $now, null, EventAuditRecorder::participationState($participation), $batch);
            $this->audit->record(AuditAction::ZoneCreated, AuditSubjectType::EventZone, $zone->id(), $municipality->id(), $actor, $now, null, EventAuditRecorder::zoneState($zone, 'AUTO_PROPOSAL'), $batch);

            return new CreatedEvent(
                $event->id()->toRfc4122(),
                $event->publicReference()->value,
                $participation->id()->toRfc4122(),
                $zone->id()->toRfc4122(),
                $timezone->identifier(),
            );
        });
    }

    /**
     * Fuseau fourni (liste blanche IANA) ou, à défaut, proposé d'après la commune organisatrice (DM-062). Jamais deviné :
     * s'il ne se détermine pas de façon fiable (ex. Polynésie française), la commande doit le fournir.
     */
    private function resolveTimezone(Municipality $municipality, ?string $requested): EventTimezone
    {
        if (null !== $requested) {
            return EventTimezone::fromIdentifier($requested);
        }

        return $this->timezones->resolve($municipality->inseeCode())
            ?? throw new DomainRuleViolation('Le fuseau horaire de votre commune ne peut pas être déterminé automatiquement : indiquez-le explicitement.');
    }

    private function newEvent(PublicReference $reference, Municipality $municipality, CreateEventCommand $command, EventTimezone $timezone, User $actor, \DateTimeImmutable $now, \App\Event\Domain\EventType $type): Event
    {
        return new Event(
            $reference,
            $municipality,
            $type,
            $command->name,
            $timezone,
            $command->startsAt,
            $command->endsAt,
            $actor,
            $now,
            $command->description,
            $command->operationalInstructions,
        );
    }
}
