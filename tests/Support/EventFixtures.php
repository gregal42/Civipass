<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventType;
use App\Event\Domain\EventZone;
use App\Event\Domain\PublicReference;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\ZoneType;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;

/**
 * Objets du module Event pour les tests, construits uniquement par leurs fabriques de domaine.
 */
final class EventFixtures
{
    private static int $sequence = 0;

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'));
    }

    public static function type(string $code = 'fire', string $label = 'Incendie'): EventType
    {
        return new EventType($code, $label);
    }

    public static function draft(?Municipality $organizer = null, ?User $creator = null, ?EventType $type = null): Event
    {
        return new Event(
            PublicReference::fromCounter(++self::$sequence, self::now()),
            $organizer ?? MunicipalityBuilder::aMunicipality()->build(),
            $type ?? self::type(),
            'Incendie du massif forestier',
            EventTimezone::fromIdentifier('Europe/Paris'),
            new \DateTimeImmutable('2026-06-10 08:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-20 18:00:00', new \DateTimeZone('UTC')),
            $creator ?? UserBuilder::aUser()->build(),
            self::now(),
        );
    }

    public static function active(?Municipality $organizer = null): Event
    {
        $event = self::draft($organizer);
        $event->activate(self::now());

        return $event;
    }

    public static function organizerParticipation(Event $event): EventParticipation
    {
        return EventParticipation::forOrganizer($event, self::now());
    }

    public static function invited(Event $event, ?Municipality $municipality = null, ?User $by = null): EventParticipation
    {
        return EventParticipation::invite($event, $municipality ?? MunicipalityBuilder::aMunicipality()->build(), $by ?? UserBuilder::aUser()->build(), null, self::now());
    }

    /** Participation invitée puis acceptée. */
    public static function accepted(Event $event, ?Municipality $municipality = null): EventParticipation
    {
        $participation = self::invited($event, $municipality);
        $participation->accept(self::now());

        return $participation;
    }

    /** Zone manuelle confirmée, active, non configurée. */
    public static function zone(EventParticipation $participation, ZoneType $type = ZoneType::Street): EventZone
    {
        return EventZone::create($participation, 'Route départementale 107', $type, null, UserBuilder::aUser()->build(), self::now(), ZoneType::WholeMunicipality === $type);
    }

    /** Zone prête pour l'activation : confirmée, active, durée de l'événement. */
    public static function readyZone(EventParticipation $participation): EventZone
    {
        $zone = self::zone($participation);
        $zone->changeValidityMode(ValidityMode::EventDuration, self::now());

        return $zone;
    }
}
