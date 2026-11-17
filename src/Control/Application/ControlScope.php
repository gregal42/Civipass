<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\Event;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Périmètre d'un appareil ou d'un code : la commune est ACTIVE, l'événement EXISTE, n'est pas clos et la participation de la commune est ACTIVE.
 * Un événement étranger à la commune (identifiant forgé) est refusé de la même façon qu'une ressource inconnue.
 */
final readonly class ControlScope
{
    public function __construct(private EventRepository $events, private EventParticipationRepository $participations)
    {
    }

    /** Événement accessible à la commune (participation quelconque), sinon refus uniforme. @throws OperationNotAllowed */
    public function eventOf(Municipality $municipality, Uuid $eventId): Event
    {
        $event = $this->events->find($eventId);
        if (null === $event || null === $this->participations->findByEventAndMunicipality($eventId, $municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $event;
    }

    /**
     * Le contrôle peut être préparé : commune ACTIVE, événement ni brouillon ni clos, participation ACTIVE.
     *
     * @throws DomainRuleViolation
     */
    public function assertOperational(Municipality $municipality, Event $event): void
    {
        if (MunicipalityStatus::Active !== $municipality->status()) {
            throw new DomainRuleViolation('La commune n’est pas active : le contrôle ne peut pas être préparé.');
        }
        if (EventStatus::Closed === $event->status()) {
            throw new DomainRuleViolation('L’événement est clos.');
        }
        $participation = $this->participations->findByEventAndMunicipality($event->id(), $municipality->id());
        if (EventStatus::Active !== $event->status() || ParticipationStatus::Active !== $participation?->status()) {
            throw new DomainRuleViolation('L’événement ou la participation de la commune n’est pas actif : le contrôle ne peut pas être préparé.');
        }
    }
}
