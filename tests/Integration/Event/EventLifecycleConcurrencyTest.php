<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Identity\Domain\User;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence sur MySQL réel (processus PHP parallèles) : une seule issue valide, jamais d'état incohérent,
 * jamais d'erreur technique non traduite.
 */
#[Group('requires-event-schema')]
final class EventLifecycleConcurrencyTest extends EventSchemaTestCase
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
        $this->em->clear();
        $event = $this->em->find(Event::class, $this->created->eventId) ?? self::fail();
        $organizer = $this->em->getRepository(EventParticipation::class)->findOneBy(['event' => $event, 'municipality' => $this->w->org]) ?? self::fail();
        $zone = EventFixtures::readyZone($organizer);
        $this->em->persist($zone->createdBy());
        $this->em->persist($zone);
        $this->em->flush();
        $this->em->clear();
        $this->service(EventLifecycleService::class)->activate($this->as($this->w->adminO), $this->created->eventId);
        $this->participationB = $this->service(EventParticipationService::class)->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestB->id()->toRfc4122()))->participationId;
        $this->service(EventParticipationService::class)->accept($this->as($this->w->adminB), $this->participationB);
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    /**
     * @param list<list<string>> $lists
     *
     * @return list<array<string, mixed>>
     */
    private function parallel(array $lists): array
    {
        $decoded = array_map(static fn (array $r): array => json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR) + ['stderr' => $r['stderr']], ParallelPhp::run('event-action.php', $lists));
        foreach ($decoded as $d) {
            if (false === $d['ok']) {
                self::assertContains($d['error'], [\App\Shared\Domain\OperationNotAllowed::class, \App\Shared\Domain\DomainRuleViolation::class, \App\Shared\Domain\OperationConflict::class], 'refus contrôlé : '.json_encode($d));
            }
        }

        return $decoded;
    }

    private function statusOf(string $sql, string $id): string
    {
        return (string) $this->em->getConnection()->fetchOne($sql, [$this->hex($id)]);
    }

    private function eventStatus(): string
    {
        return $this->statusOf('SELECT status FROM event_event WHERE id = UNHEX(?)', $this->created->eventId);
    }

    public function testC3AcceptanceRacingTheGlobalClosure(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET status = 'INVITED' WHERE id = UNHEX(?)", [$this->hex($this->participationB)]);
        $results = $this->parallel([
            [$this->w->adminB->id()->toRfc4122(), 'accept', $this->participationB],
            [$this->w->adminO->id()->toRfc4122(), 'close-event', $this->created->eventId],
        ]);

        self::assertTrue($results[1]['ok'], json_encode($results));
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM event_participation WHERE status NOT IN ('CLOSED')"), 'aucune participation ouverte après la clôture : '.json_encode($results));
    }

    public function testC4TwoGlobalClosuresProduceASingleClosure(): void
    {
        $o = $this->w->adminO->id()->toRfc4122();
        $results = $this->parallel([[$o, 'close-event', $this->created->eventId], [$o, 'close-event', $this->created->eventId]]);

        self::assertCount(1, array_filter($results, static fn (array $r): bool => true === $r['ok']), json_encode($results));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_CLOSED'"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(DISTINCT batch_id) FROM audit_log WHERE action IN ('EVENT_CLOSED','PARTICIPATION_CLOSED')"));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PARTICIPATION_CLOSED'"));
    }

    public function testC5SuspensionRacingTheGlobalClosureNeverLeavesAnOpenParticipation(): void
    {
        $results = $this->parallel([
            [$this->w->adminO->id()->toRfc4122(), 'suspend-event', $this->created->eventId],
            [$this->w->adminO->id()->toRfc4122(), 'close-event', $this->created->eventId],
            [$this->w->adminB->id()->toRfc4122(), 'suspend', $this->participationB],
        ]);

        self::assertTrue($results[1]['ok'], json_encode($results));
        self::assertSame('CLOSED', $this->eventStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM event_participation WHERE status <> 'CLOSED'"), json_encode($results));
    }

    public function testC6TwoSuspensionsOfTheEventKeepASingleSuspension(): void
    {
        $o = $this->w->adminO->id()->toRfc4122();
        $results = $this->parallel([[$o, 'suspend-event', $this->created->eventId, 'A'], [$o, 'suspend-event', $this->created->eventId, 'B']]);

        self::assertCount(1, array_filter($results, static fn (array $r): bool => true === $r['ok']), json_encode($results));
        self::assertSame('SUSPENDED', $this->eventStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'EVENT_SUSPENDED'"));
    }

    public function testC7TwoParticipationClosuresAndAParticipationActionDoNotCorruptState(): void
    {
        $b = $this->w->adminB->id()->toRfc4122();
        $results = $this->parallel([[$b, 'close', $this->participationB], [$b, 'close', $this->participationB], [$b, 'suspend', $this->participationB]]);

        self::assertGreaterThanOrEqual(1, \count(array_filter($results, static fn (array $r): bool => true === $r['ok'])));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PARTICIPATION_CLOSED'"));
        self::assertSame('CLOSED', $this->statusOf('SELECT status FROM event_participation WHERE id = UNHEX(?)', $this->participationB));
    }

    public function testC11ParticipationsOfDifferentMunicipalitiesRunWithoutBlockingEachOther(): void
    {
        $a = $this->service(EventParticipationService::class)->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->other->id()->toRfc4122()))->participationId;
        $results = $this->parallel([[$this->w->adminB->id()->toRfc4122(), 'suspend', $this->participationB], [$this->w->adminX->id()->toRfc4122(), 'accept', $a]]);

        foreach ($results as $r) {
            self::assertTrue($r['ok'], json_encode($results));
        }
    }

    public function testC12EventSuspensionRacingAParticipationSuspensionBothSucceedOrAreRefusedCleanly(): void
    {
        $results = $this->parallel([
            [$this->w->adminO->id()->toRfc4122(), 'suspend-event', $this->created->eventId],
            [$this->w->adminB->id()->toRfc4122(), 'suspend', $this->participationB],
        ]);

        foreach ($results as $r) {
            self::assertTrue($r['ok'], json_encode($results));
        }
        self::assertSame('SUSPENDED', $this->eventStatus());
        self::assertSame('SUSPENDED', $this->statusOf('SELECT status FROM event_participation WHERE id = UNHEX(?)', $this->participationB));
    }
}
