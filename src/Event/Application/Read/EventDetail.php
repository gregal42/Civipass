<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Application\AccessDenialReason;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;

/**
 * Fiche d'un événement vue par UNE commune : ce que sa relation à l'événement l'autorise à voir. Une commune
 * simplement invitée n'a ni description ni dates de cycle de vie (informations minimales de DM-084) ; les participations
 * suivent le niveau de visibilité de `participants`. Aucune entité, aucun champ interne.
 */
final readonly class EventDetail
{
    /**
     * @param list<ActionAvailability> $actions actions pertinentes pour cette commune et cet état, avec leur disponibilité
     */
    public function __construct(
        public string $eventId,
        public string $publicReference,
        public string $name,
        public string $typeCode,
        public string $typeLabel,
        public EventStatus $status,
        public string $organizerMunicipalityId,
        public string $organizerMunicipalityName,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public string $timezone,
        public EventRelation $relation,
        public ?ParticipationStatus $myParticipationStatus,
        public ?string $description,
        /** Consignes opérationnelles : réservées à la commune organisatrice (jamais aux participantes ni aux invitées). */
        public ?string $operationalInstructions,
        public ?EventLifecycleDates $lifecycle,
        public ParticipantList $participants,
        public array $actions,
        public ?bool $activationReady,
        /** Identifiant de la participation de la commune qui consulte (jamais pour l'organisatrice, jamais lu depuis le client). */
        public ?string $ownParticipationId = null,
        /** Participation gratuite : commune participante sans droit d'utilisation propre (DM-086) ; sert à l'information seulement. */
        public bool $freeParticipation = false,
    ) {
    }

    public function action(EventAction $action): ?ActionAvailability
    {
        foreach ($this->actions as $candidate) {
            if ($candidate->action === $action) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Action à AFFICHER : une action refusée à cause du rôle ou du statut de la commune est absente (l'écran explique alors
     * la consultation seule, design system : « on n'affiche jamais un bouton que l'utilisateur ne peut pas utiliser »).
     */
    public function visibleAction(EventAction $action): ?ActionAvailability
    {
        $candidate = $this->action($action);

        return null !== $candidate && ($candidate->allowed || !\in_array($candidate->reason, [AccessDenialReason::RoleNotAllowed, AccessDenialReason::MunicipalityNotWritable], true)) ? $candidate : null;
    }

    /**
     * Motif de la consultation seule : renseigné lorsque la commune a des actions pertinentes mais qu'AUCUNE n'est permise
     * à cause du rôle ou du statut de la commune (jamais pour un état de l'événement). Null sinon.
     */
    public function readOnlyReason(): ?AccessDenialReason
    {
        if ([] === $this->actions) {
            return null;
        }
        foreach ($this->actions as $candidate) {
            if ($candidate->allowed || !\in_array($candidate->reason, [AccessDenialReason::RoleNotAllowed, AccessDenialReason::MunicipalityNotWritable], true)) {
                return null;
            }
        }

        return $this->actions[0]->reason;
    }
}
