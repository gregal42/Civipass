<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\EventZoneReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Service\EventZoneService;
use App\Identity\Domain\User;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture des zones d'un événement par une commune (MySQL de test) : visibilité (DM-084), actions décidées par la politique
 * d'accès, aucune action sur les zones des autres communes ni sur un événement clôturé, refus uniformes.
 */
#[Group('requires-event-schema')]
final class EventZoneReaderTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private string $event;
    private string $participationO;
    private string $participationB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $created = $this->service(EventCreationService::class)->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))));
        $this->event = $created->eventId;
        $this->participationO = (string) $this->em->getConnection()->fetchOne('SELECT BIN_TO_UUID(id) FROM event_participation WHERE event_id = UUID_TO_BIN(?) AND municipality_id = ?', [$this->event, $this->w->org->id()->toBinary()]);
        $participations = $this->service(EventParticipationService::class);
        $this->participationB = $participations->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $this->w->guestB->id()->toRfc4122()))->participationId;
        $participations->accept($this->as($this->w->adminB), $this->participationB);
        $participations->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $this->w->other->id()->toRfc4122()));
        $this->service(EventZoneService::class)->create($this->as($this->w->adminO), $this->participationO, new CreateZoneCommand('Quartier du port', 'DISTRICT'));
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function reader(): EventZoneReader
    {
        return $this->service(EventZoneReader::class);
    }

    public function testTheOrganizerSeesItsZonesFirstWithActionsAndTheProposedOneNotConfirmed(): void
    {
        $board = $this->reader()->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event);

        self::assertSame($this->participationO, $board->ownParticipationId);
        self::assertTrue($board->create?->allowed);
        $own = $board->groups[0];
        self::assertTrue($own->own);
        self::assertTrue($own->organizer);
        self::assertCount(2, $own->zones);
        $byLabel = [];
        foreach ($own->zones as $zone) {
            $byLabel[$zone->label] = $zone;
        }
        $proposed = $byLabel['Commune entière — '.$this->w->org->name()] ?? self::fail('zone proposée absente');
        self::assertFalse($proposed->isConfirmed());
        self::assertTrue($proposed->action(EventAction::ConfirmZone)?->allowed);
        self::assertNull($byLabel['Quartier du port']->action(EventAction::ConfirmZone), 'déjà confirmée : action sans objet');
        self::assertTrue($byLabel['Quartier du port']->action(EventAction::SuspendZone)?->allowed);
        self::assertNull($byLabel['Quartier du port']->action(EventAction::ReactivateZone));
    }

    public function testAParticipantSeesTheOrganizersZonesWithoutAnyAction(): void
    {
        $board = $this->reader()->forEvent($this->as($this->w->adminB), $this->w->guestB->id()->toRfc4122(), $this->event);

        $own = $board->groups[0];
        self::assertTrue($own->own);
        self::assertSame($this->participationB, $board->ownParticipationId);
        $organizer = $board->groups[1] ?? self::fail('zones de l’organisatrice non visibles');
        self::assertFalse($organizer->own);
        self::assertNotEmpty($organizer->zones);
        foreach ($organizer->zones as $zone) {
            self::assertFalse($zone->own);
            self::assertSame([], $zone->actions, 'aucune action sur les zones d’une autre commune');
        }
    }

    public function testAnInvitedCommuneSeesNoZoneAndCannotCreate(): void
    {
        $board = $this->reader()->forEvent($this->as($this->w->adminX), $this->w->other->id()->toRfc4122(), $this->event);

        self::assertSame([], $board->groups);
        self::assertNull($board->ownParticipationId);
        self::assertNull($board->create);
    }

    public function testAManagerAndAnInstructorGetNoWriteAction(): void
    {
        foreach ([$this->w->managerO, $this->w->instructorO] as $user) {
            $board = $this->reader()->forEvent($this->as($user), $this->w->org->id()->toRfc4122(), $this->event);
            self::assertFalse($board->create?->allowed);
            foreach ($board->groups[0]->zones as $zone) {
                foreach ($zone->actions as $action) {
                    self::assertFalse($action->allowed);
                }
                self::assertNull($zone->visibleAction(EventAction::UpdateZone), 'refus de rôle : action non affichée');
            }
        }
    }

    public function testAClosedEventOffersNoAction(): void
    {
        $this->service(EventLifecycleService::class)->close($this->as($this->w->adminO), $this->event);

        $board = $this->reader()->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event);

        self::assertNull($board->create);
        foreach ($board->groups[0]->zones as $zone) {
            self::assertSame([], $zone->actions);
        }
    }

    public function testAZoneIsFoundOnlyAmongTheVisibleOnes(): void
    {
        $board = $this->reader()->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event);

        self::assertNull($board->zone(Uuid::v7()->toRfc4122()));
        self::assertNotNull($board->zone($board->groups[0]->zones[0]->zoneId));
    }

    public function testAnUnrelatedCommuneOrAForgedEventIsRefusedUniformly(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->reader()->forEvent($this->as($this->w->adminX), $this->w->org->id()->toRfc4122(), $this->event);
    }

    public function testAnUnknownEventIsRefusedUniformly(): void
    {
        $this->expectException(OperationNotAllowed::class);
        $this->reader()->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), Uuid::v7()->toRfc4122());
    }

    public function testTheZonesOfAnInvitedCommuneAreHiddenUntilItAcceptsButStayInTheDatabase(): void
    {
        $other = $this->w->other->id()->toRfc4122();
        $participationX = (string) $this->em->getConnection()->fetchOne('SELECT BIN_TO_UUID(id) FROM event_participation WHERE event_id = UUID_TO_BIN(?) AND municipality_id = ?', [$this->event, $this->w->other->id()->toBinary()]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_zone WHERE participation_id = UUID_TO_BIN(?)', [$participationX]), 'zone proposée conservée');

        foreach ([[$this->w->adminO, $this->w->org], [$this->w->adminB, $this->w->guestB]] as [$user, $municipality]) {
            $board = $this->reader()->forEvent($this->as($user), $municipality->id()->toRfc4122(), $this->event);
            self::assertNotContains($other, array_map(static fn ($g): string => $g->municipalityId, $board->groups), 'invitation non acceptée : zones non listées');
        }

        $this->service(EventParticipationService::class)->accept($this->as($this->w->adminX), $participationX);

        $board = $this->reader()->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event);
        self::assertContains($other, array_map(static fn ($g): string => $g->municipalityId, $board->groups), 'visible après acceptation');
    }
}
