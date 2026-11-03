<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\EventListReader;
use App\Event\Application\EventParticipantReader;
use App\Event\Application\ParticipantListVisibility as V;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\ParticipationStatus as S;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\EventScenario;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Listes sécurisées sur MySQL : restreintes dans la requête (jamais « tout charger puis filtrer »), projections
 * dédiées, aucune donnée interne dans la coordination, aucun privilège cumulé entre communes.
 */
#[Group('requires-event-schema')]
final class EventReadingIntegrationTest extends EventSchemaTestCase
{
    private EventScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = EventScenario::create();
        $s = $this->s;
        // L'organisatrice invite D avec un message ; B est suspendue avec un motif INTERNE.
        $s->participationB->suspend('Motif interne de suspension', F::now());
        $this->save(...$s->entities);
    }

    private function lists(): EventListReader
    {
        return $this->service(EventListReader::class);
    }

    private function participants(): EventParticipantReader
    {
        return $this->service(EventParticipantReader::class);
    }

    private static function id(object $entityWithId): string
    {
        return $entityWithId->id()->toRfc4122();
    }

    /**
     * Événements supplémentaires : un événement de C (étranger à B) et un événement organisé par B.
     *
     * @return array{\App\Event\Domain\Event, \App\Event\Domain\Event}
     */
    private function addOtherEvents(): array
    {
        $s = $this->s;
        $type = $s->event->type();
        $ofC = F::draft($s->c, $s->adminC, $type);
        $ofB = F::draft($s->b, $s->adminB, $type);
        $this->save($ofC, F::organizerParticipation($ofC), $ofB, F::organizerParticipation($ofB));

        return [$ofC, $ofB];
    }

    // ── Liste des événements ─────────────────────────────────────────────────

    public function testEventListsAreRestrictedToTheMunicipalityPerimeter(): void
    {
        $s = $this->s;
        [$ofC, $ofB] = $this->addOtherEvents();

        $forA = $this->lists()->forMunicipality($s->adminA, self::id($s->a));
        $forB = $this->lists()->forMunicipality($s->adminB, self::id($s->b));
        $forC = $this->lists()->forMunicipality($s->adminC, self::id($s->c));
        $forD = $this->lists()->forMunicipality($s->adminD, self::id($s->d));

        self::assertSame([self::id($s->event)], array_map(static fn ($i): string => $i->eventId, $forA));
        self::assertEqualsCanonicalizing([self::id($s->event), self::id($ofB)], array_map(static fn ($i): string => $i->eventId, $forB));
        self::assertSame([self::id($ofC)], array_map(static fn ($i): string => $i->eventId, $forC), 'C ne voit que son propre événement');
        self::assertSame([self::id($s->event)], array_map(static fn ($i): string => $i->eventId, $forD), 'invitée : voit l\'événement');
    }

    public function testEventListItemsCarryTheViewersOwnParticipationStatusOnly(): void
    {
        $s = $this->s;
        $this->addOtherEvents();

        $byEvent = [];
        foreach ($this->lists()->forMunicipality($s->adminB, self::id($s->b)) as $item) {
            $byEvent[$item->eventId] = $item;
        }
        $shared = $byEvent[self::id($s->event)];

        self::assertSame(S::Suspended, $shared->myParticipationStatus);
        self::assertSame($s->a->name(), $shared->organizerMunicipalityName);
        self::assertSame($s->event->publicReference()->value, $shared->publicReference);
        self::assertSame('fire', $shared->typeCode);
        self::assertNull($byEvent[array_values(array_diff(array_keys($byEvent), [self::id($s->event)]))[0]]->myParticipationStatus, 'organisatrice de cet événement');
        self::assertSame(S::Invited, $this->lists()->forMunicipality($s->adminD, self::id($s->d))[0]->myParticipationStatus);
        self::assertNull($this->lists()->forMunicipality($s->adminA, self::id($s->a))[0]->myParticipationStatus);
    }

    public function testListsAreOpenToEveryRoleOfTheMunicipalityIncludingInstructors(): void
    {
        $s = $this->s;

        foreach ([$s->adminA, $s->managerA, $s->instructorA] as $user) {
            self::assertCount(1, $this->lists()->forMunicipality($user, self::id($s->a)));
        }
    }

    public function testForgedMunicipalityContextsAreRefusedWithAUniformMessage(): void
    {
        $s = $this->s;
        $attempts = [
            'C usurpe A' => fn () => $this->lists()->forMunicipality($s->adminC, self::id($s->a)),
            'B usurpe A' => fn () => $this->lists()->forMunicipality($s->adminB, self::id($s->a)),
            'super-admin CWS' => fn () => $this->lists()->forMunicipality($s->superAdmin, self::id($s->a)),
            'commune inexistante' => fn () => $this->lists()->forMunicipality($s->adminA, Uuid::v7()->toRfc4122()),
            'identifiant invalide' => fn () => $this->lists()->forMunicipality($s->adminA, "1' OR 1=1 --"),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                self::fail($label.' devait être refusé.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Commune inaccessible.', $e->getMessage(), $label);
            }
        }
    }

    public function testAdministrativeStatusAndMembershipApplyToLists(): void
    {
        $s = $this->s;
        $inactiveMembership = UserBuilder::aUser()->build();
        $this->save($inactiveMembership, MembershipBuilder::aMembership($inactiveMembership, $s->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->inactive()->build());
        $inactiveUser = UserBuilder::aUser()->inactive()->build();
        $this->save($inactiveUser, MembershipBuilder::aMembership($inactiveUser, $s->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        foreach ([$inactiveMembership, $inactiveUser] as $user) {
            try {
                $this->lists()->forMunicipality($user, self::id($s->a));
                self::fail('Membership ou compte inactif : refus attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }

        $s->a->changeStatus(MunicipalityStatus::Suspended, F::now());
        $this->em->flush();
        self::assertCount(1, $this->lists()->forMunicipality($s->adminA, self::id($s->a)), 'commune SUSPENDED : lecture autorisée');

        $s->a->changeStatus(MunicipalityStatus::Inactive, F::now());
        $this->em->flush();
        $this->expectException(OperationNotAllowed::class);
        $this->lists()->forMunicipality($s->adminA, self::id($s->a));
    }

    public function testListLimitIsBoundedAndOrdered(): void
    {
        $s = $this->s;
        [, $ofB] = $this->addOtherEvents();

        $one = $this->lists()->forMunicipality($s->adminB, self::id($s->b), 1);
        self::assertCount(1, $one);
        self::assertSame(self::id($ofB), $one[0]->eventId, 'du plus récent au plus ancien');
        self::assertCount(2, $this->lists()->forMunicipality($s->adminB, self::id($s->b), 100000));
        self::assertCount(1, $this->lists()->forMunicipality($s->adminB, self::id($s->b), -5), 'borne basse');
    }

    // ── Participations : organisatrice ───────────────────────────────────────

    public function testTheOrganizerSeesEveryParticipationWithItsManagementInformation(): void
    {
        $s = $this->s;

        foreach ([$s->adminA, $s->managerA] as $user) {
            $list = $this->participants()->forEvent($user, self::id($s->a), self::id($s->event));

            self::assertSame(V::Full, $list->visibility);
            self::assertCount(3, $list->details);
            self::assertSame([], $list->summaries);
            self::assertNull($list->invitation);
            $byName = [];
            foreach ($list->details as $detail) {
                $byName[$detail->municipalityName] = $detail;
            }
            self::assertTrue($byName[$s->a->name()]->organizer);
            self::assertSame(S::Suspended, $byName[$s->b->name()]->status);
            self::assertSame('Motif interne de suspension', $byName[$s->b->name()]->suspensionReason, 'visibilité complète de l\'organisatrice');
            self::assertSame(S::Invited, $byName[$s->d->name()]->status, 'invitations en attente visibles de l\'organisatrice');
            self::assertSame($s->a->name(), $list->details[0]->municipalityName, 'l\'organisatrice d\'abord');
        }
    }

    // ── Participations : participantes ───────────────────────────────────────

    public function testAnAcceptedParticipantSeesOnlyTheCoordinationList(): void
    {
        $s = $this->s;

        $list = $this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event));

        self::assertSame(V::Coordination, $list->visibility, 'B SUSPENDED : visibilité conservée');
        self::assertSame([], $list->details);
        self::assertNull($list->invitation);
        self::assertSame([$s->a->name(), $s->b->name()], array_map(static fn ($x): string => $x->municipalityName, $list->summaries), 'organisatrice + ACTIVE/SUSPENDED ; D (invitée) exclue');
        self::assertSame([true, false], array_map(static fn ($x): bool => $x->organizer, $list->summaries));
        self::assertSame(S::Suspended, $list->summaries[1]->status);
    }

    public function testCoordinationNeverExposesInternalData(): void
    {
        $s = $this->s;
        $list = $this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event));

        foreach ($list->summaries as $summary) {
            self::assertSame(['municipalityId', 'municipalityName', 'status', 'organizer'], array_keys(get_object_vars($summary)));
        }
        self::assertStringNotContainsString('Motif interne', serialize($list), 'le motif de suspension ne sort jamais de la couche de lecture');
        self::assertStringNotContainsString($s->adminA->email(), serialize($list));
    }

    public function testCoordinationFollowsParticipationStatuses(): void
    {
        $s = $this->s;
        // D accepte : elle apparaît ; puis elle est clôturée : elle disparaît pour les autres participantes.
        $s->participationD->accept(F::now());
        $this->em->flush();
        $names = static fn ($list): array => array_map(static fn ($x): string => $x->municipalityName, $list->summaries);

        self::assertSame([$s->a->name(), $s->b->name(), $s->d->name()], $names($this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event))));

        $s->participationD->close(F::now());
        $this->em->flush();
        self::assertSame([$s->a->name(), $s->b->name()], $names($this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event))), 'CLOSED non exposée aux autres participantes');
        self::assertCount(3, $this->participants()->forEvent($s->adminA, self::id($s->a), self::id($s->event))->details, 'l\'organisatrice garde la visibilité complète');
    }

    public function testAFormerParticipantWhoseParticipationIsClosedKeepsTheLimitedList(): void
    {
        $s = $this->s;
        $s->participationB->reactivate(F::now());
        $s->participationB->close(F::now());
        $this->em->flush();

        $list = $this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event));

        self::assertSame(V::Coordination, $list->visibility);
        self::assertSame([$s->a->name()], array_map(static fn ($x): string => $x->municipalityName, $list->summaries), 'sa propre participation clôturée n\'est plus listée');
    }

    // ── Participations : invitée, étrangère ──────────────────────────────────

    public function testAnInvitedMunicipalitySeesOnlyItsOwnInvitation(): void
    {
        $s = $this->s;

        $list = $this->participants()->forEvent($s->adminD, self::id($s->d), self::id($s->event));

        self::assertSame(V::OwnInvitationOnly, $list->visibility);
        self::assertSame([], $list->summaries);
        self::assertSame([], $list->details);
        self::assertNotNull($list->invitation);
        self::assertSame(S::Invited, $list->invitation->status);
        self::assertSame($s->event->name(), $list->invitation->eventName);
        self::assertSame($s->a->name(), $list->invitation->organizerMunicipalityName);
        self::assertStringNotContainsString($s->b->name(), serialize($list), 'aucune autre commune');
    }

    public function testAForeignMunicipalityOrForgedIdentifierSeesNothing(): void
    {
        $s = $this->s;
        $attempts = [
            'commune étrangère' => [fn () => $this->participants()->forEvent($s->adminC, self::id($s->c), self::id($s->event)), 'Ressource inaccessible.'],
            'événement inexistant' => [fn () => $this->participants()->forEvent($s->adminA, self::id($s->a), Uuid::v7()->toRfc4122()), 'Ressource inaccessible.'],
            'identifiant invalide' => [fn () => $this->participants()->forEvent($s->adminA, self::id($s->a), 'pas-un-uuid'), 'Ressource inaccessible.'],
            'C usurpe le contexte de A' => [fn () => $this->participants()->forEvent($s->adminC, self::id($s->a), self::id($s->event)), 'Commune inaccessible.'],
            'B usurpe le contexte de A' => [fn () => $this->participants()->forEvent($s->adminB, self::id($s->a), self::id($s->event)), 'Commune inaccessible.'],
            'super-admin CWS' => [fn () => $this->participants()->forEvent($s->superAdmin, self::id($s->a), self::id($s->event)), 'Commune inaccessible.'],
        ];

        foreach ($attempts as $label => [$attempt, $message]) {
            try {
                $attempt();
                self::fail($label.' devait être refusé.');
            } catch (OperationNotAllowed $e) {
                self::assertSame($message, $e->getMessage(), $label);
            }
        }
    }

    public function testPrivilegesNeverAddUpBetweenMunicipalitiesOfTheSameUser(): void
    {
        $s = $this->s;
        $dual = UserBuilder::aUser()->build();
        $this->save($dual, MembershipBuilder::aMembership($dual, $s->a)->withRoles(MunicipalityRole::Instructor)->build(), MembershipBuilder::aMembership($dual, $s->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());

        self::assertSame(V::Coordination, $this->participants()->forEvent($dual, self::id($s->a), self::id($s->event))->visibility, 'instructeur de l\'organisatrice : liste limitée');
        self::assertSame(V::Coordination, $this->participants()->forEvent($dual, self::id($s->b), self::id($s->event))->visibility, 'en tant que B, jamais le niveau de A');
        self::assertCount(1, $this->lists()->forMunicipality($dual, self::id($s->a)));
        self::assertCount(1, $this->lists()->forMunicipality($dual, self::id($s->b)));
    }

    // ── U5 et U6 ─────────────────────────────────────────────────────────────

    public function testTheInstructorOfTheOrganizerGetsTheLimitedListWithoutInternalData(): void
    {
        $s = $this->s;

        $list = $this->participants()->forEvent($s->instructorA, self::id($s->a), self::id($s->event));

        self::assertSame(V::Coordination, $list->visibility);
        self::assertSame([], $list->details);
        self::assertSame([$s->a->name(), $s->b->name()], array_map(static fn ($x): string => $x->municipalityName, $list->summaries), 'D (invitation en attente) invisible');
        self::assertStringNotContainsString('Motif interne', serialize($list));
    }

    public function testAfterClosureTheHistoryListsOnlyCommunesThatActuallyParticipated(): void
    {
        $s = $this->s;
        $s->participationB->reactivate(F::now());
        $s->event->close(F::now());
        foreach ([$s->participationA, $s->participationB, $s->participationD] as $participation) {
            $participation->closeWithEvent(F::now());
        }
        $this->em->flush();
        $names = static fn ($list): array => array_map(static fn ($x): string => $x->municipalityName, $list->history);

        $forB = $this->participants()->forEvent($s->adminB, self::id($s->b), self::id($s->event));
        self::assertSame(V::History, $forB->visibility);
        self::assertSame([$s->a->name(), $s->b->name()], $names($forB), 'D n\'a jamais accepté : absente de l\'historique');
        self::assertSame([true, false], array_map(static fn ($x): bool => $x->organizer, $forB->history));
        foreach ($forB->history as $entry) {
            self::assertSame(['municipalityId', 'municipalityName', 'organizer'], array_keys(get_object_vars($entry)));
        }
        self::assertStringNotContainsString('Motif interne', serialize($forB));

        self::assertSame(V::History, $this->participants()->forEvent($s->instructorA, self::id($s->a), self::id($s->event))->visibility);
        self::assertSame(V::Full, $this->participants()->forEvent($s->adminA, self::id($s->a), self::id($s->event))->visibility);

        $forD = $this->participants()->forEvent($s->adminD, self::id($s->d), self::id($s->event));
        self::assertSame(V::OwnInvitationOnly, $forD->visibility, 'invitation jamais acceptée');
        self::assertSame([], $forD->history);
        self::assertSame(S::Closed, $forD->invitation?->status);
    }

    public function testNoParticipationRowOfAnEventTheMunicipalityDoesNotKnowIsEverReturned(): void
    {
        $s = $this->s;
        [$ofC] = $this->addOtherEvents();
        $this->save(EventParticipation::invite($ofC, $s->d, $s->adminC, 'Message de C à D', F::now()));

        // B ne participe pas à l'événement de C : aucune visibilité, même avec l'identifiant exact.
        $this->expectException(OperationNotAllowed::class);
        $this->participants()->forEvent($s->adminB, self::id($s->b), self::id($ofC));
    }
}
