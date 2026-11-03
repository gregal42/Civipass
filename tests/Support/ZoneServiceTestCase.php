<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Service\EventZoneService;
use App\Identity\Domain\User;

/**
 * Monde commun aux tests des services de zones (MySQL de test) : événement DRAFT de la commune organisatrice `org`,
 * participation de `guestB` invitée puis acceptée (commune SANS abonnement ni accès commercial), zones « Commune
 * entière » proposées pour chacune (non confirmées, validité non configurée).
 */
abstract class ZoneServiceTestCase extends EventSchemaTestCase
{
    protected ServiceWorld $w;
    protected CreatedEvent $created;
    protected string $participationB;
    protected string $proposedZoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->created = $this->service(EventCreationService::class)->create(
            $this->as($this->w->adminO),
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))),
        );
        $invitation = $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestB->id()->toRfc4122()));
        $this->participationB = $invitation->participationId;
        $this->proposedZoneB = $invitation->proposedZoneId;
        $this->participations()->accept($this->as($this->w->adminB), $this->participationB);
    }

    protected function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    protected function zoneService(): EventZoneService
    {
        return $this->service(EventZoneService::class);
    }

    protected function lifecycle(): EventLifecycleService
    {
        return $this->service(EventLifecycleService::class);
    }

    protected function participations(): EventParticipationService
    {
        return $this->service(EventParticipationService::class);
    }

    protected function rows(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }

    protected function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    protected function zoneColumn(string $zoneId, string $column): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT '.$column.' FROM event_zone WHERE id = UNHEX(?)', [$this->hex($zoneId)]);
    }

    protected function eventStatus(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM event_event WHERE id = UNHEX(?)', [$this->hex($this->created->eventId)]);
    }

    protected function auditCount(string $action): int
    {
        return $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = '".$action."'");
    }

    /** Durée de l'événement : la configuration la plus simple. */
    protected function eventDuration(): ZoneValidityCommand
    {
        return new ZoneValidityCommand('EVENT_DURATION');
    }

    /**
     * @param list<array{date: string, field: string, occurrence: string}> $dstChoices
     */
    protected function punctual(string $date = '2026-12-02', string $start = '09:00', string $end = '17:00', array $dstChoices = []): ValiditySlotInput
    {
        return new ValiditySlotInput('PUNCTUAL', $date, $start, $end, null, [], $dstChoices);
    }

    protected function periods(ValiditySlotInput ...$slots): ZoneValidityCommand
    {
        return new ZoneValidityCommand('SPECIFIC_PERIODS', array_values($slots));
    }

    /** Zone proposée de l'organisatrice : confirmée et configurée (toute la durée) ⇒ l'événement est activable. */
    protected function makeEventActivatable(): void
    {
        $zone = $this->created->proposedZoneId;
        $this->zoneService()->confirm($this->as($this->w->adminO), $zone);
        $this->zoneService()->configureValidity($this->as($this->w->adminO), $zone, $this->eventDuration());
    }

    protected function activateEvent(): void
    {
        $this->makeEventActivatable();
        $this->lifecycle()->activate($this->as($this->w->adminO), $this->created->eventId);
    }
}
