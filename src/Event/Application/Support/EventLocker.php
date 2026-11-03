<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Acquisition ordonnée des verrous (D2) : événement → participations (identifiant croissant) → zones ; le compteur
 * de références n'est pris qu'à la création, en dernier, par PublicReferenceGenerator.
 *
 * L'ordre est imposé par les signatures : verrouiller une participation exige l'événement DÉJÀ verrouillé (il est passé
 * en argument), et vérifie que la participation lui appartient. Le mode (partagé ou exclusif) est toujours explicite :
 * aucun verrou ne change de mode implicitement. À appeler en PREMIÈRE instruction de la transaction (avant toute lecture
 * métier sensible), uniquement dans une transaction : les verrous tombent avec elle, rien n'est conservé au-delà.
 * Inexistant et non rattaché produisent le même refus uniforme.
 */
final readonly class EventLocker
{
    public function __construct(
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private Connection $connection,
    ) {
    }

    /** Verrou exclusif : modification, activation, suspension, clôture, invitation. */
    public function exclusiveEvent(Uuid $eventId): Event
    {
        $this->assertInTransaction();

        return $this->events->lock($eventId) ?? throw self::inaccessible();
    }

    /** Verrou partagé : participations et zones (ne doivent pas coexister avec activation ou clôture, sans s'exclure entre elles). */
    public function sharedEvent(Uuid $eventId): Event
    {
        $this->assertInTransaction();

        return $this->events->lockShared($eventId) ?? throw self::inaccessible();
    }

    /** Verrou exclusif d'une participation, de l'événement déjà verrouillé auquel elle doit appartenir. */
    public function participation(Uuid $participationId, Event $lockedEvent): EventParticipation
    {
        $this->assertInTransaction();
        $participation = $this->participations->lock($participationId);
        if (null === $participation || !$participation->event()->id()->equals($lockedEvent->id())) {
            throw self::inaccessible();
        }

        return $participation;
    }

    /**
     * Verrou exclusif de TOUTES les participations de l'événement déjà verrouillé, par identifiant croissant (clôture).
     *
     * @return list<EventParticipation>
     */
    public function allParticipations(Event $lockedEvent): array
    {
        $this->assertInTransaction();

        return $this->participations->lockByEvent($lockedEvent->id());
    }

    /** Verrou exclusif d'une zone de la participation déjà verrouillée (ordre : événement → participation → zones). */
    public function zone(Uuid $zoneId, EventParticipation $lockedParticipation): EventZone
    {
        $this->assertInTransaction();
        $zone = $this->zones->lock($zoneId);
        if (null === $zone || !$zone->participation()->id()->equals($lockedParticipation->id())) {
            throw self::inaccessible();
        }

        return $zone;
    }

    /**
     * Verrou exclusif des zones demandées, par identifiant croissant ; le nombre trouvé doit égaler le nombre demandé
     * (zone étrangère, inexistante ou supprimée : refus en bloc, DM-028).
     *
     * @param list<Uuid> $zoneIds identifiants distincts
     *
     * @return list<EventZone>
     */
    public function zones(array $zoneIds, EventParticipation $lockedParticipation): array
    {
        $this->assertInTransaction();
        $zones = $this->zones->lockByIdsInParticipation($zoneIds, $lockedParticipation->id());
        if (\count($zones) !== \count($zoneIds)) {
            throw self::inaccessible();
        }

        return $zones;
    }

    private function assertInTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Les verrous ne s\'acquièrent que dans la transaction de l\'opération.');
        }
    }

    private static function inaccessible(): OperationNotAllowed
    {
        return new OperationNotAllowed('Ressource inaccessible.');
    }
}
