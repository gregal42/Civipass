<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\AccessDenialReason;
use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\EventDetailReader;
use App\Event\Application\EventListReader;
use App\Event\Application\ParticipantListVisibility;
use App\Event\Application\Read\EventListQuery;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Service\EventZoneService;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventType;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures de la liste et de la fiche (MySQL de test) : visibilité par relation, indicateurs d'actions décidés par la
 * politique d'accès, filtres et pagination en SQL, refus uniformes.
 */
#[Group('requires-event-schema')]
final class EventDetailReaderTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private string $event;
    private string $participationB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->event = $this->service(EventCreationService::class)->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))))->eventId;
        $this->participationB = $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $this->w->guestB->id()->toRfc4122()))->participationId;
        $this->participations()->accept($this->as($this->w->adminB), $this->participationB);
        $this->participations()->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $this->w->other->id()->toRfc4122()));
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function participations(): EventParticipationService
    {
        return $this->service(EventParticipationService::class);
    }

    private function reader(): EventDetailReader
    {
        return $this->service(EventDetailReader::class);
    }

    private function id(object $municipality): string
    {
        return $municipality->id()->toRfc4122();
    }

    /**
     * @return array<string, array{bool, ?AccessDenialReason}>
     */
    private function actions(User $user, object $municipality): array
    {
        $result = [];
        foreach ($this->reader()->forEvent($this->as($user), $this->id($municipality), $this->event)->actions as $a) {
            $result[$a->action->value] = [$a->allowed, $a->reason];
        }

        return $result;
    }

    private function makeReadyAndActivate(): void
    {
        $admin = $this->as($this->w->adminO);
        $zone = (string) $this->em->getConnection()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(z.id),1,8),\'-\',SUBSTR(HEX(z.id),9,4),\'-\',SUBSTR(HEX(z.id),13,4),\'-\',SUBSTR(HEX(z.id),17,4),\'-\',SUBSTR(HEX(z.id),21))) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.municipality_id = ?', [$this->w->org->id()->toBinary()]);
        $this->service(EventZoneService::class)->confirm($admin, $zone);
        $this->service(EventZoneService::class)->configureValidity($admin, $zone, new ZoneValidityCommand('EVENT_DURATION'));
        $this->service(EventLifecycleService::class)->activate($admin, $this->event);
    }

    // ── Relation et contenu selon la commune ─────────────────────────────────

    public function testEachRelationSeesOnlyWhatItsLevelAllows(): void
    {
        $organizer = $this->reader()->forEvent($this->as($this->w->adminO), $this->id($this->w->org), $this->event);
        self::assertSame(EventRelation::Organizer, $organizer->relation);
        self::assertSame(ParticipantListVisibility::Full, $organizer->participants->visibility);
        self::assertNotNull($organizer->lifecycle);
        self::assertSame('Europe/Paris', $organizer->timezone);
        self::assertStringStartsWith('EVT-', $organizer->publicReference);
        self::assertSame(3, \count($organizer->participants->details), 'organisatrice, B acceptée, invitation en attente');
        self::assertSame(2, $organizer->participants->participatingCount());

        $participant = $this->reader()->forEvent($this->as($this->w->adminB), $this->id($this->w->guestB), $this->event);
        self::assertSame(EventRelation::Participant, $participant->relation);
        self::assertSame(ParticipationStatus::Active, $participant->myParticipationStatus);
        self::assertSame(ParticipantListVisibility::Coordination, $participant->participants->visibility);
        self::assertNotNull($participant->lifecycle);

        $invited = $this->reader()->forEvent($this->as($this->w->adminX), $this->id($this->w->other), $this->event);
        self::assertSame(EventRelation::Invited, $invited->relation);
        self::assertNull($invited->description);
        self::assertNull($invited->lifecycle, 'informations minimales');
        self::assertNotNull($invited->participants->invitation);
        self::assertSame([], $invited->participants->summaries);
        self::assertNull($invited->participants->participatingCount());
    }

    // ── Indicateurs d'actions décidés par la politique ───────────────────────

    public function testOrganizerActionsByRoleOnADraft(): void
    {
        $expected = ['update', 'invite', 'activate', 'close'];
        foreach ([$this->w->adminO, $this->w->managerO] as $manager) {
            $actions = $this->actions($manager, $this->w->org);
            self::assertSame($expected, array_keys($actions));
            foreach ($actions as [$allowed, $reason]) {
                self::assertTrue($allowed);
                self::assertNull($reason);
            }
        }

        $instructor = $this->actions($this->w->instructorO, $this->w->org);
        self::assertSame($expected, array_keys($instructor));
        foreach ($instructor as [$allowed, $reason]) {
            self::assertFalse($allowed);
            self::assertSame(AccessDenialReason::RoleNotAllowed, $reason);
        }
        $detail = $this->reader()->forEvent($this->as($this->w->instructorO), $this->id($this->w->org), $this->event);
        self::assertSame(AccessDenialReason::RoleNotAllowed, $detail->readOnlyReason());
    }

    public function testActionsFollowTheStateOfTheEvent(): void
    {
        $this->makeReadyAndActivate();
        self::assertSame(['update', 'invite', 'suspend', 'close'], array_keys($this->actions($this->w->adminO, $this->w->org)));

        $this->service(EventLifecycleService::class)->suspend($this->as($this->w->adminO), $this->event, 'Alerte');
        self::assertSame(['update', 'invite', 'reactivate', 'close'], array_keys($this->actions($this->w->adminO, $this->w->org)));

        $this->service(EventLifecycleService::class)->close($this->as($this->w->adminO), $this->event);
        self::assertSame([], $this->actions($this->w->adminO, $this->w->org), 'événement clôturé : aucune action');
        self::assertSame(EventStatus::Closed, $this->reader()->forEvent($this->as($this->w->adminO), $this->id($this->w->org), $this->event)->status);
    }

    public function testParticipationAndInvitationActions(): void
    {
        $participant = $this->actions($this->w->adminB, $this->w->guestB);
        self::assertSame(['suspend_participation', 'close_participation'], array_keys($participant));
        self::assertTrue($participant['suspend_participation'][0]);

        $invited = $this->actions($this->w->adminX, $this->w->other);
        self::assertSame(['accept_invitation'], array_keys($invited));
        self::assertTrue($invited['accept_invitation'][0]);
    }

    public function testASuspendedMunicipalityGetsEveryActionDeniedForItsStatus(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->em->clear();

        $detail = $this->reader()->forEvent($this->as($this->w->adminO), $this->id($this->w->org), $this->event);

        foreach ($detail->actions as $a) {
            self::assertFalse($a->allowed);
            self::assertSame(AccessDenialReason::MunicipalityNotWritable, $a->reason);
        }
        self::assertSame(AccessDenialReason::MunicipalityNotWritable, $detail->readOnlyReason());
    }

    public function testActivationReadinessIsOnlyReportedToTheOrganizerOfADraft(): void
    {
        self::assertFalse($this->reader()->forEvent($this->as($this->w->adminO), $this->id($this->w->org), $this->event)->activationReady);
        self::assertNull($this->reader()->forEvent($this->as($this->w->adminB), $this->id($this->w->guestB), $this->event)->activationReady);

        $this->makeReadyAndActivate();
        self::assertNull($this->reader()->forEvent($this->as($this->w->adminO), $this->id($this->w->org), $this->event)->activationReady, 'plus un brouillon');
    }

    // ── Refus uniformes ──────────────────────────────────────────────────────

    public function testEveryInaccessibleCombinationIsRefusedTheSameWay(): void
    {
        $cases = [
            [$this->w->adminX, $this->w->org, $this->event],        // commune étrangère dans l'URL
            [$this->w->adminO, $this->w->guestB, $this->event],     // commune dont l'utilisateur n'est pas membre
            [$this->w->adminO, $this->w->org, Uuid::v7()->toRfc4122()],
            [$this->w->adminO, $this->w->org, 'not-a-uuid'],
            [$this->w->superAdmin, $this->w->org, $this->event],    // aucun droit communal implicite
        ];
        foreach ($cases as [$user, $municipality, $event]) {
            try {
                $this->reader()->forEvent($this->as($user), $this->id($municipality), $event);
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
        // guestA : commune sans lien avec l'événement, avec un membre : refusée aussi.
        $unrelated = \App\Tests\Support\UserBuilder::aUser()->build();
        $this->save($unrelated, \App\Tests\Support\MembershipBuilder::aMembership($unrelated, $this->em->find(\App\Municipality\Domain\Municipality::class, $this->w->guestA->id()) ?? self::fail())->withRoles(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin)->build());
        $this->expectException(OperationNotAllowed::class);
        $this->reader()->forEvent($this->as($unrelated), $this->id($this->w->guestA), $this->event);
    }

    // ── Liste : filtres et pagination en SQL ─────────────────────────────────

    public function testTheListIsFilteredAndPaginatedInSqlWithinTheMunicipalityScope(): void
    {
        $admin = $this->as($this->w->adminO);
        for ($i = 1; $i <= 25; ++$i) {
            $this->service(EventCreationService::class)->create($admin, $this->w->org->id()->toRfc4122(), new CreateEventCommand('flood', sprintf('Crue %02d', $i), new \DateTimeImmutable(sprintf('2027-02-%02d 08:00:00', $i), new \DateTimeZone('UTC'))));
        }
        $reader = $this->service(EventListReader::class);

        $page = $reader->search($admin, $this->id($this->w->org), new EventListQuery(typeCode: 'flood', page: 2, perPage: 10));
        self::assertSame(25, $page->total);
        self::assertSame(3, $page->pages);
        self::assertCount(10, $page->items);
        self::assertSame('Crue 15', $page->items[0]->name, 'début le plus récent d\'abord, page 2');
        self::assertSame('Europe/Paris', $page->items[0]->timezone);

        $ascending = $reader->search($admin, $this->id($this->w->org), new EventListQuery(typeCode: 'flood', direction: 'asc', page: 3, perPage: 10));
        self::assertSame(['Crue 21', 'Crue 22', 'Crue 23', 'Crue 24', 'Crue 25'], array_map(static fn ($i): string => $i->name, $ascending->items));

        self::assertSame(26, $reader->search($admin, $this->id($this->w->org), new EventListQuery())->total);
        self::assertSame(1, $reader->search($admin, $this->id($this->w->org), new EventListQuery(status: EventStatus::Draft, typeCode: 'fire'))->total);
        self::assertSame(0, $reader->search($admin, $this->id($this->w->org), new EventListQuery(status: EventStatus::Closed))->total);

        // Périmètre : B ne voit que l'événement auquel elle participe.
        $forB = $reader->search($this->as($this->w->adminB), $this->id($this->w->guestB), new EventListQuery());
        self::assertSame(1, $forB->total);
        self::assertFalse($forB->create?->allowed, 'guestB n\'a aucun droit d\'utilisation : création refusée');
        self::assertSame(AccessDenialReason::NoUsageRight, $forB->create->reason);
        self::assertTrue($reader->search($admin, $this->id($this->w->org), new EventListQuery())->create?->allowed);
    }

    public function testTypeOptionsIncludeDeactivatedTypesStillUsedByEvents(): void
    {
        $type = $this->em->getRepository(EventType::class)->findOneBy(['code' => 'fire']) ?? self::fail();
        $type->deactivate();
        $this->em->flush();

        $codes = array_map(static fn ($t): string => $t->code, $this->service(EventListReader::class)->typeOptions());

        self::assertContains('fire', $codes);
        self::assertCount(9, $codes);
    }
}
