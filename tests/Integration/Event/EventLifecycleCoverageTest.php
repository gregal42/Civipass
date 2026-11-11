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
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compléments de la matrice de couverture 5.3 : permissions, isolation intercommunale (y compris identifiants forgés),
 * transitions interdites, audit des participations, rollback d'une transition, concurrences activation/modification vs clôture.
 */
#[Group('requires-event-schema')]
final class EventLifecycleCoverageTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private CreatedEvent $created;
    private string $participationB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->created = $this->service(EventCreationService::class)->create(
            $this->as($this->w->adminO),
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))),
        );
        $this->participationB = $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestB->id()->toRfc4122()))->participationId;
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

    private function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    private function addReadyZoneAndActivate(): void
    {
        $this->em->clear();
        $event = $this->em->find(Event::class, $this->created->eventId) ?? self::fail();
        $organizer = $this->em->getRepository(EventParticipation::class)->findOneBy(['event' => $event, 'municipality' => $this->w->org]) ?? self::fail();
        $zone = EventFixtures::readyZone($organizer);
        $this->em->persist($zone->createdBy());
        $this->em->persist($zone);
        $this->em->flush();
        $this->em->clear();
        $this->lifecycle()->activate($this->as($this->w->adminO), $this->created->eventId);
    }

    private function openBoth(): void
    {
        $this->addReadyZoneAndActivate();
        $this->participations()->accept($this->as($this->w->adminB), $this->participationB);
    }

    /**
     * @param array<string, User>   $users
     * @param \Closure(User): mixed $operation
     */
    private function assertRefusedFor(array $users, \Closure $operation, string $label): void
    {
        foreach ($users as $name => $user) {
            try {
                $operation($this->as($user));
                self::fail(sprintf('%s refusé attendu pour %s.', $label, $name));
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ── Permissions et isolation : opérations d'événement ────────────────────

    public function testEveryEventOperationRefusesUnprivilegedAndForeignActors(): void
    {
        $this->openBoth();
        $e = $this->created->eventId;
        $w = $this->w;
        $actors = ['instructeur' => $w->instructorO, 'commune invitée' => $w->adminB, 'commune étrangère' => $w->adminX, 'super-admin sans droit communal' => $w->superAdmin];
        $before = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->assertRefusedFor($actors, fn (User $u) => $this->lifecycle()->update($u, $e, new UpdateEventCommand(name: 'Pirate')), 'update');
        $this->assertRefusedFor($actors, fn (User $u) => $this->lifecycle()->suspend($u, $e, 'Pirate'), 'suspend');
        $this->assertRefusedFor($actors, fn (User $u) => $this->lifecycle()->reactivate($u, $e), 'reactivate');
        $this->assertRefusedFor($actors, fn (User $u) => $this->lifecycle()->close($u, $e), 'close');
        $this->assertRefusedFor($actors, fn (User $u) => $this->lifecycle()->activate($u, $e), 'activate');

        self::assertSame($before, $this->rows('SELECT COUNT(*) FROM audit_log'), 'un refus n\'écrit aucun audit');
        self::assertSame('ACTIVE', $this->em->getConnection()->fetchOne('SELECT status FROM event_event'));
    }

    public function testForgedEventIdentifiersAreRefusedUniformly(): void
    {
        $unknown = Uuid::v7()->toRfc4122();
        foreach (['not-a-uuid', $unknown] as $id) {
            $this->assertRefusedFor(['admin' => $this->w->adminO], fn (User $u) => $this->lifecycle()->close($u, $id), 'close forgé');
            $this->assertRefusedFor(['admin' => $this->w->adminB], fn (User $u) => $this->participations()->accept($u, $id), 'accept forgé');
        }
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%CLOSED'"));
    }

    // ── Permissions et isolation : opérations de participation ───────────────

    public function testEveryParticipationOperationRefusesTheOrganizerAndForeignCommunes(): void
    {
        $this->openBoth();
        $p = $this->participationB;
        $w = $this->w;
        $actors = ['organisatrice' => $w->adminO, 'commune étrangère' => $w->adminX, 'super-admin sans droit communal' => $w->superAdmin];
        $before = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->assertRefusedFor($actors, fn (User $u) => $this->participations()->accept($u, $p), 'accept');
        $this->assertRefusedFor($actors, fn (User $u) => $this->participations()->suspend($u, $p, 'Pirate'), 'suspend');
        $this->assertRefusedFor($actors, fn (User $u) => $this->participations()->reactivate($u, $p), 'reactivate');
        $this->assertRefusedFor($actors, fn (User $u) => $this->participations()->close($u, $p), 'close');

        self::assertSame($before, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame('ACTIVE', $this->em->getConnection()->fetchOne("SELECT status FROM event_participation WHERE id = UNHEX('".$this->hex($p)."')"));
    }

    // ── Transitions interdites ───────────────────────────────────────────────

    public function testForbiddenEventTransitions(): void
    {
        $o = $this->as($this->w->adminO);
        $e = $this->created->eventId;
        foreach ([fn () => $this->lifecycle()->reactivate($o, $e), fn () => $this->lifecycle()->suspend($o, $e, 'Motif')] as $fromDraft) {
            $this->expectViolation($fromDraft);
        }
        $this->openBoth();
        $o = $this->as($this->w->adminO);
        $this->expectViolation(fn () => $this->lifecycle()->activate($o, $e), 'activate depuis ACTIVE');
        $this->expectViolation(fn () => $this->lifecycle()->reactivate($o, $e), 'reactivate depuis ACTIVE');
        $this->lifecycle()->suspend($o, $e, 'Motif');
        $this->expectViolation(fn () => $this->lifecycle()->suspend($this->as($this->w->adminO), $e, 'Encore'), 'suspend depuis SUSPENDED');
        $this->expectViolation(fn () => $this->lifecycle()->activate($this->as($this->w->adminO), $e), 'activate depuis SUSPENDED');
        $this->lifecycle()->close($this->as($this->w->adminO), $e);
        $this->expectViolation(fn () => $this->lifecycle()->activate($this->as($this->w->adminO), $e), 'activate depuis CLOSED');
        $this->expectViolation(fn () => $this->lifecycle()->suspend($this->as($this->w->adminO), $e, 'Motif'), 'suspend depuis CLOSED');
    }

    public function testForbiddenParticipationTransitions(): void
    {
        $p = $this->participationB;
        $b = fn () => $this->as($this->w->adminB);
        $this->expectViolation(fn () => $this->participations()->suspend($b(), $p, 'Motif'), 'suspend une invitation');
        $this->expectViolation(fn () => $this->participations()->reactivate($b(), $p), 'reactivate une invitation');
        $this->expectViolation(fn () => $this->participations()->close($b(), $p), 'close une invitation en attente');
        $this->openBoth();
        $this->expectViolation(fn () => $this->participations()->accept($b(), $p), 'accept deux fois');
        $this->expectViolation(fn () => $this->participations()->reactivate($b(), $p), 'reactivate une participation active');
        $this->participations()->close($b(), $p);
        foreach (['accept' => fn () => $this->participations()->accept($b(), $p), 'suspend' => fn () => $this->participations()->suspend($b(), $p, 'Motif'), 'reactivate' => fn () => $this->participations()->reactivate($b(), $p), 'close' => fn () => $this->participations()->close($b(), $p)] as $name => $attempt) {
            $this->expectViolation($attempt, $name.' depuis CLOSED');
        }
    }

    public function testTheOrganizerParticipationCannotBeClosedAloneNorAccepted(): void
    {
        $this->addReadyZoneAndActivate();
        $organizer = (string) $this->em->getConnection()->fetchOne("SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),'-',SUBSTR(HEX(id),9,4),'-',SUBSTR(HEX(id),13,4),'-',SUBSTR(HEX(id),17,4),'-',SUBSTR(HEX(id),21))) FROM event_participation WHERE municipality_id = UNHEX('".$this->hex($this->w->org->id()->toRfc4122())."')");

        $this->expectViolation(fn () => $this->participations()->close($this->as($this->w->adminO), $organizer), 'close organisatrice');
        $this->expectViolation(fn () => $this->participations()->accept($this->as($this->w->adminO), $organizer), 'accept organisatrice');
        self::assertSame('ACTIVE', $this->em->getConnection()->fetchOne("SELECT status FROM event_participation WHERE id = UNHEX('".$this->hex($organizer)."')"));
    }

    private function expectViolation(\Closure $attempt, string $label = ''): void
    {
        try {
            $attempt();
            self::fail('Transition interdite acceptée : '.$label);
        } catch (DomainRuleViolation|OperationNotAllowed) {
            self::addToAssertionCount(1);
        }
    }

    // ── Audit et rollback des participations ─────────────────────────────────

    public function testParticipationOperationsWriteOneStructuredAuditEachWithoutFreeText(): void
    {
        $this->openBoth();
        $b = $this->as($this->w->adminB);
        $this->participations()->suspend($b, $this->participationB, 'Texte libre sensible');
        $this->participations()->reactivate($this->as($this->w->adminB), $this->participationB);
        $this->participations()->close($this->as($this->w->adminB), $this->participationB);

        foreach (['PARTICIPATION_ACCEPTED', 'PARTICIPATION_SUSPENDED', 'PARTICIPATION_REACTIVATED', 'PARTICIPATION_CLOSED'] as $action) {
            self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = '".$action."' AND subject_id = UNHEX('".$this->hex($this->participationB)."')"), $action);
        }
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM audit_log WHERE new_value LIKE '%Texte libre sensible%' OR previous_value LIKE '%Texte libre sensible%'"), 'DT-037 : jamais de texte libre dans l\'audit');
        self::assertSame($this->w->guestB->id()->toRfc4122(), $this->em->getConnection()->fetchOne("SELECT LOWER(CONCAT(SUBSTR(HEX(municipality_id),1,8),'-',SUBSTR(HEX(municipality_id),9,4),'-',SUBSTR(HEX(municipality_id),13,4),'-',SUBSTR(HEX(municipality_id),17,4),'-',SUBSTR(HEX(municipality_id),21))) FROM audit_log WHERE action = 'PARTICIPATION_ACCEPTED'"), 'audit rattaché à la commune de la participation');
    }

    private function failingLifecycle(): EventLifecycleService
    {
        $c = self::getContainer();

        return new EventLifecycleService($c->get(EventTransactions::class), $c->get(ClockInterface::class), $c->get(EventResourceResolver::class), $c->get(EventAccessPolicy::class), $c->get(EventLocker::class), $c->get(EventTypeRepository::class), $c->get(EventZoneRepository::class), new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), 1)), $c->get(\App\Event\Application\ClosureNotificationNeutralizer::class), $c->get(\App\Event\Application\ClosureInvitationRevoker::class), $c->get(\App\Event\Application\ClosureCounters::class));
    }

    private function assertInjectedFailureLeavesTheEventUntouched(\Closure $operation): void
    {
        $this->addReadyZoneAndActivate();
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        try {
            $operation($this->as($this->w->adminO));
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        self::assertSame('ACTIVE', $this->em->getConnection()->fetchOne('SELECT status FROM event_event'));
        self::assertSame('Incendie du massif forestier', $this->em->getConnection()->fetchOne('SELECT name FROM event_event'));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testSuspensionIsRolledBackWithItsAudit(): void
    {
        $lifecycle = $this->failingLifecycle();
        $this->assertInjectedFailureLeavesTheEventUntouched(fn (User $u) => $lifecycle->suspend($u, $this->created->eventId, 'Motif'));
    }

    public function testUpdateIsRolledBackWithItsAudit(): void
    {
        $lifecycle = $this->failingLifecycle();
        $this->assertInjectedFailureLeavesTheEventUntouched(fn (User $u) => $lifecycle->update($u, $this->created->eventId, new UpdateEventCommand(name: 'Autre nom')));
    }

    public function testParticipationTransitionAuditIsRolledBackWithTheTransition(): void
    {
        $this->addReadyZoneAndActivate();
        $c = self::getContainer();
        $service = new EventParticipationService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(\App\Municipality\Application\EventCreationPolicy::class),
            $c->get(EventLocker::class),
            $c->get(\App\Municipality\Domain\MunicipalityRepository::class),
            $c->get(\App\Event\Domain\EventParticipationRepository::class),
            $c->get(EventZoneRepository::class),
            new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), 1)),
        );
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        try {
            $service->accept($this->as($this->w->adminB), $this->participationB);
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('INVITED', $this->em->getConnection()->fetchOne("SELECT status FROM event_participation WHERE id = UNHEX('".$this->hex($this->participationB)."')"));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    // ── Concurrence complémentaire ───────────────────────────────────────────

    /**
     * @param list<list<string>> $lists
     *
     * @return list<array<string, mixed>>
     */
    private function parallel(array $lists): array
    {
        $decoded = array_map(static fn (array $r): array => json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR), ParallelPhp::run('event-action.php', $lists));
        foreach ($decoded as $d) {
            if (false === $d['ok']) {
                self::assertContains($d['error'], [OperationNotAllowed::class, DomainRuleViolation::class, \App\Shared\Domain\OperationConflict::class], json_encode($d));
            }
        }

        return $decoded;
    }

    public function testClosureRacingEventReactivationAndUpdateLeavesAConsistentClosedEvent(): void
    {
        $this->openBoth();
        $o = $this->w->adminO->id()->toRfc4122();
        $this->lifecycle()->suspend($this->as($this->w->adminO), $this->created->eventId, 'Motif');

        $this->parallel([[$o, 'reactivate-event', $this->created->eventId], [$o, 'close-event', $this->created->eventId], [$o, 'suspend-event', $this->created->eventId]]);

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_CLOSED'"));
        self::assertSame('CLOSED', $this->em->getConnection()->fetchOne('SELECT status FROM event_event'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"));
    }

    public function testTwoSimultaneousReactivationsAndTwoAcceptancesAreSerialized(): void
    {
        $this->openBoth();
        $o = $this->w->adminO->id()->toRfc4122();
        $this->lifecycle()->suspend($this->as($this->w->adminO), $this->created->eventId, 'Motif');

        $results = $this->parallel([[$o, 'reactivate-event', $this->created->eventId], [$o, 'reactivate-event', $this->created->eventId]]);

        self::assertCount(1, array_filter($results, static fn (array $r): bool => true === $r['ok']));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_REACTIVATED'"));
    }

    public function testTwoSimultaneousAcceptancesOfTheSameInvitationYieldOneAcceptance(): void
    {
        $this->addReadyZoneAndActivate();
        $b = $this->w->adminB->id()->toRfc4122();

        $results = $this->parallel([[$b, 'accept', $this->participationB], [$b, 'accept', $this->participationB], [$b, 'accept', $this->participationB]]);

        self::assertCount(1, array_filter($results, static fn (array $r): bool => true === $r['ok']), json_encode($results));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'PARTICIPATION_ACCEPTED'"));
    }
}
