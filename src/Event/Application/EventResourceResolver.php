<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\EventZoneValidityRepository;
use App\Identity\Domain\User;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Point d'entrée des services pour un identifiant NON FIABLE (requête, formulaire) : charge la ressource puis exige
 * la permission demandée ; « inexistant », « identifiant invalide » et « non autorisé » produisent le même refus
 * (« Ressource inaccessible. ») : aucune énumération, aucun droit tiré de la connaissance d'un UUID.
 * La commune agissante se déduit de la chaîne de rattachement (EventAccessPolicy), jamais d'un paramètre client.
 */
final readonly class EventResourceResolver
{
    private const INACCESSIBLE = 'Ressource inaccessible.';

    public function __construct(
        private EventAccessPolicy $policy,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private EventZoneValidityRepository $slots,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function event(User $user, string $eventId, EventPermission $permission): Event
    {
        $event = null === ($id = self::uuid($eventId)) ? null : $this->events->find($id);
        if (null === $event) {
            throw new OperationNotAllowed(self::INACCESSIBLE);
        }
        $this->policy->forEvent($user, $permission, $event)->orThrow();

        return $event;
    }

    /**
     * @throws OperationNotAllowed
     */
    public function participation(User $user, string $participationId, EventPermission $permission): EventParticipation
    {
        $participation = null === ($id = self::uuid($participationId)) ? null : $this->participations->find($id);
        if (null === $participation) {
            throw new OperationNotAllowed(self::INACCESSIBLE);
        }
        $this->policy->forParticipation($user, $permission, $participation)->orThrow();

        return $participation;
    }

    /**
     * @throws OperationNotAllowed
     */
    public function zone(User $user, string $zoneId, EventPermission $permission): EventZone
    {
        $zone = null === ($id = self::uuid($zoneId)) ? null : $this->zones->find($id);
        if (null === $zone) {
            throw new OperationNotAllowed(self::INACCESSIBLE);
        }
        $this->policy->forZone($user, $permission, $zone)->orThrow();

        return $zone;
    }

    /**
     * @throws OperationNotAllowed
     */
    public function slot(User $user, string $slotId, EventPermission $permission): EventZoneValidity
    {
        $slot = null === ($id = self::uuid($slotId)) ? null : $this->slots->find($id);
        if (null === $slot) {
            throw new OperationNotAllowed(self::INACCESSIBLE);
        }
        $this->policy->forSlot($user, $permission, $slot)->orThrow();

        return $slot;
    }

    private static function uuid(string $value): ?Uuid
    {
        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
