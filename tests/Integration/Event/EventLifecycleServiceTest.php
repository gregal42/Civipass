<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

#[Group('requires-event-schema')]
final class EventLifecycleServiceTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private CreatedEvent $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->created = $this->service(EventCreationService::class)->create(
            $this->as($this->w->adminO),
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))),
        );
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function lifecycle(): EventLifecycleService
    {
        return $this->service(EventLifecycleService::class);
    }

    private function participations(): EventParticipationService
    {
        return $this->service(EventParticipationService::class);
    }

    private function rows(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }

    private function eventStatus(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM event_event WHERE id = UNHEX(?)', [str_replace('-', '', $this->created->eventId)]);
    }

    /** Ajoute une zone prête sur la participation organisatrice (activation possible). */
    private function addReadyZone(): void
    {
        $this->em->clear();
        $event = $this->em->find(Event::class, $this->created->eventId) ?? self::fail();
        $organizer = $this->em->getRepository(EventParticipation::class)->findOneBy(['event' => $event, 'municipality' => $this->w->org]) ?? self::fail();
        $zone = EventFixtures::readyZone($organizer);
        $this->em->persist($zone->createdBy());
        $this->em->persist($zone);
        $this->em->flush();
        $this->em->clear();
    }

    private function activate(): void
    {
        $this->addReadyZone();
        $this->lifecycle()->activate($this->as($this->w->adminO), $this->created->eventId);
    }

    /**
     * Invite guestA et guestB, acceptée par B ; retourne [idA, idB].
     *
     * @return array{string, string}
     */
    private function inviteBoth(bool $accept = true): array
    {
        $a = $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestA->id()->toRfc4122()));
        $b = $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestB->id()->toRfc4122()));
        if ($accept) {
            $this->participations()->accept($this->as($this->w->adminB), $b->participationId);
        }

        return [$a->participationId, $b->participationId];
    }

    public function testActivationRequiresAReadyZone(): void
    {
        try {
            $this->lifecycle()->activate($this->as($this->w->adminO), $this->created->eventId);
            self::fail();
        } catch (DomainRuleViolation) {
        }
        self::assertSame('DRAFT', $this->eventStatus());

        $this->activate();
        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_ACTIVATED'"));
    }

    public function testSuspendKeepsTheLastReasonAfterReactivation(): void
    {
        $this->activate();
        $this->lifecycle()->suspend($this->as($this->w->adminO), $this->created->eventId, 'Route coupée');
        self::assertSame('SUSPENDED', $this->eventStatus());
        $this->lifecycle()->reactivate($this->as($this->w->adminO), $this->created->eventId);

        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame('Route coupée', $this->em->getConnection()->fetchOne('SELECT suspension_reason FROM event_event'));
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT suspended_at FROM event_event'));
    }

    public function testSuspendRequiresAReasonAndAnActiveEvent(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->lifecycle()->suspend($this->as($this->w->adminO), $this->created->eventId, 'x');
    }

    public function testUpdateWritesOneAuditAndNoopWritesNone(): void
    {
        $r = $this->lifecycle()->update($this->as($this->w->adminO), $this->created->eventId, new UpdateEventCommand(name: 'Incendie du massif'));
        self::assertSame(['name'], $r->changedFields);
        $audits = $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_UPDATED'");
        self::assertSame(1, $audits);

        $r = $this->lifecycle()->update($this->as($this->w->adminO), $this->created->eventId, new UpdateEventCommand(name: 'Incendie du massif'));
        self::assertSame([], $r->changedFields);
        self::assertSame($audits, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_UPDATED'"));
    }

    public function testInvalidUpdateIsAtomic(): void
    {
        $this->expectException(DomainRuleViolation::class);
        try {
            $this->lifecycle()->update($this->as($this->w->adminO), $this->created->eventId, new UpdateEventCommand(name: 'Nouveau nom', startsAt: new \DateTimeImmutable('2026-12-05 08:00:00', new \DateTimeZone('UTC')), endsAt: new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))));
        } finally {
            self::assertSame('Incendie du massif forestier', $this->em->getConnection()->fetchOne('SELECT name FROM event_event'));
            self::assertSame(0, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_UPDATED'"));
        }
    }

    public function testUpdateIsForbiddenToAGuestAndToAnInstructor(): void
    {
        foreach ([$this->w->adminB, $this->w->instructorO, $this->w->adminX] as $user) {
            try {
                $this->lifecycle()->update($this->as($user), $this->created->eventId, new UpdateEventCommand(name: 'Pirate'));
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
            }
        }
        self::assertSame('Incendie du massif forestier', $this->em->getConnection()->fetchOne('SELECT name FROM event_event'));
    }

    public function testGlobalClosureClosesEveryOpenParticipationInOneBatch(): void
    {
        $this->activate();
        [, $b] = $this->inviteBoth();
        $this->participations()->suspend($this->as($this->w->adminB), $b, 'Moyens indisponibles');

        $result = $this->lifecycle()->close($this->as($this->w->adminO), $this->created->eventId);

        self::assertSame(EventStatus::Active, $result->previousStatus);
        self::assertCount(3, $result->closedParticipations); // organisatrice + invitée + suspendue
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"));
        self::assertSame(4, $this->rows("SELECT COUNT(*) FROM audit_log WHERE batch_id = UNHEX('".str_replace('-', '', $result->batchId)."')"));
        // l'historique est conservé (U6) : rien n'est supprimé
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM event_participation'));
    }

    public function testClosureFromDraftIsAllowedAndIrreversible(): void
    {
        $result = $this->lifecycle()->close($this->as($this->w->adminO), $this->created->eventId);
        self::assertSame(EventStatus::Draft, $result->previousStatus);

        foreach ([fn () => $this->lifecycle()->close($this->as($this->w->adminO), $this->created->eventId), fn () => $this->lifecycle()->reactivate($this->as($this->w->adminO), $this->created->eventId), fn () => $this->lifecycle()->update($this->as($this->w->adminO), $this->created->eventId, new UpdateEventCommand(name: 'Tard'))] as $attempt) {
            try {
                $attempt();
                self::fail('Refus attendu après la clôture.');
            } catch (OperationNotAllowed|DomainRuleViolation) {
            }
        }
        self::assertSame('CLOSED', $this->eventStatus());
    }

    public function testAnInvitationCannotBeAcceptedNorReinvitedAfterClosure(): void
    {
        [$a, $b] = $this->inviteBoth(false);
        $this->lifecycle()->close($this->as($this->w->adminO), $this->created->eventId);

        foreach ([fn () => $this->participations()->accept($this->as($this->w->adminB), $b), fn () => $this->participations()->resendInvitation($this->as($this->w->adminO), $a)] as $attempt) {
            try {
                $attempt();
                self::fail('Refus attendu après la clôture.');
            } catch (OperationNotAllowed|DomainRuleViolation) {
            }
        }
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status IN ('ACTIVE','INVITED')"));
    }

    public function testFailureDuringClosureRollsBackEverything(): void
    {
        $this->activate();
        $this->inviteBoth();
        $c = self::getContainer();
        $service = new EventLifecycleService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(EventLocker::class),
            $c->get(EventTypeRepository::class),
            $c->get(EventZoneRepository::class),
            new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), 3)),
            $c->get(\App\Event\Application\ClosureNotificationNeutralizer::class),
            $c->get(\App\Event\Application\ClosureInvitationRevoker::class),
            $c->get(\App\Event\Application\ClosureCounters::class),
        );
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        try {
            $service->close($this->as($this->w->adminO), $this->created->eventId);
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status = 'CLOSED'"));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testParticipationLifecycleAndIsolation(): void
    {
        $this->activate();
        [, $b] = $this->inviteBoth();

        // une autre commune ne peut rien faire sur la participation de B
        foreach ([$this->w->adminX, $this->w->adminO] as $intruder) {
            try {
                $this->participations()->suspend($this->as($intruder), $b, 'Intrusion');
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
            }
        }
        $this->participations()->suspend($this->as($this->w->adminB), $b, 'Moyens indisponibles');
        $this->participations()->reactivate($this->as($this->w->adminB), $b);
        self::assertSame('Moyens indisponibles', $this->em->getConnection()->fetchOne("SELECT suspension_reason FROM event_participation WHERE id = UNHEX('".str_replace('-', '', $b)."')"));
        $r = $this->participations()->close($this->as($this->w->adminB), $b);

        self::assertSame(ParticipationStatus::Closed, $r->status);
        $this->expectException(DomainRuleViolation::class);
        $this->participations()->reactivate($this->as($this->w->adminB), $b);
    }

    public function testOnlyTheInvitedMunicipalityAdministratorAccepts(): void
    {
        [$a] = $this->inviteBoth(false);

        $this->expectException(OperationNotAllowed::class);
        $this->participations()->accept($this->as($this->w->adminX), $a);
    }
}
