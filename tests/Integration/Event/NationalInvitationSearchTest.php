<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\InvitationCandidateReader;
use App\Event\Application\Read\InvitationCandidate;
use App\Event\Application\Read\InvitationCandidateState;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;

/**
 * Recherche d'une commune à inviter dans le référentiel NATIONAL (MySQL de test) : par nom (accents, tirets, casse), par code INSEE
 * exact et par préfixe, commune non encore inscrite, situation vis-à-vis de l'événement, aucun doublon, éligibilité inchangée.
 */
#[Group('requires-event-schema')]
final class NationalInvitationSearchTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private string $event;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['municipality_national_commune'])) {
            self::markTestSkipped('Référentiel national non migré sur la base de test.');
        }
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->event = $this->service(EventCreationService::class)->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), new CreateEventCommand('fire', 'Incendie', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))))->eventId;
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function commune(string $code, string $name, bool $active = true): void
    {
        $commune = new NationalCommune(new NationalCommuneRecord($code, $name, substr($code, 0, 2), '75', ['33000'], 1000), 'test', EventFixtures::now());
        if (!$active) {
            $commune->deactivate(EventFixtures::now());
        }
        $this->em->persist($commune);
        $this->em->flush();
    }

    private function register(string $code, string $name, MunicipalityStatus $status = MunicipalityStatus::Active): Municipality
    {
        $municipality = new Municipality($name, InseeCode::fromString($code), EventFixtures::now());
        if (MunicipalityStatus::Active !== $status) {
            $municipality->changeStatus($status, EventFixtures::now());
        }
        $this->em->persist($municipality);
        $this->em->flush();

        return $municipality;
    }

    /**
     * @return array<int|string, InvitationCandidate> indexés par code INSEE
     */
    private function search(string $term): array
    {
        /** @var array<int|string, InvitationCandidate> $found */
        $found = [];
        foreach ($this->service(InvitationCandidateReader::class)->search($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, $term)->items as $candidate) {
            self::assertArrayNotHasKey($candidate->inseeCode, $found, 'aucun doublon : '.$candidate->inseeCode);
            $found[$candidate->inseeCode] = $candidate;
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function codes(string $term): array
    {
        return array_map('strval', array_keys($this->search($term)));
    }

    public function testACommuneAbsentFromCivipassButInTheReferentialIsFoundAsNotRegistered(): void
    {
        $this->commune('33503', 'Saumos');

        $found = $this->search('Saumos');

        self::assertSame(['33503'], array_map('strval', array_keys($found)));
        self::assertFalse($found['33503']->registered);
        self::assertNull($found['33503']->municipalityId);
        self::assertSame(InvitationCandidateState::Available, $found['33503']->state);
    }

    public function testTheSearchIgnoresCaseAccentsApostrophesAndHyphens(): void
    {
        $this->commune('01001', 'L’Abergement-Clémenciat');
        $this->commune('85113', 'L’Île-d’Yeu');

        foreach (['abergement', 'ABERGEMENT clemenciat', 'l abergement', 'clémenci', 'Abergement-Clémenciat'] as $term) {
            self::assertSame(['01001'], $this->codes($term), $term);
        }
        self::assertSame(['85113'], $this->codes('ile d yeu'));
    }

    public function testTheSearchByInseeCodeIsExactOrAPrefix(): void
    {
        $this->commune('33503', 'Saumos');
        $this->commune('33529', 'Le Temple');
        $this->commune('24333', 'Autre');

        self::assertSame(['33503'], $this->codes('33503'), 'code exact');
        self::assertEqualsCanonicalizing(['33503', '33529'], $this->codes('335'), 'préfixe du code INSEE');
        self::assertEqualsCanonicalizing(['33503', '33529'], $this->codes('33'));
        self::assertSame([], $this->search('3350x'));
    }

    public function testCorsicanAndOverseasCodesAreSearchable(): void
    {
        $this->commune('2A001', 'Afa');
        $this->commune('97101', 'Les Abymes');

        self::assertSame(['2A001'], $this->codes('2A0'));
        self::assertSame(['97101'], $this->codes('971'));
    }

    public function testTheSearchRanksExactThenPrefixThenContains(): void
    {
        $this->commune('33001', 'Temple');
        $this->commune('33002', 'Temple-de-la-Lande');
        $this->commune('33003', 'Le Grand Temple');

        self::assertSame(['33001', '33002', '33003'], $this->codes('Temple'));
    }

    public function testARegisteredCommuneInTheReferentialAppearsOnceWithItsIdentifier(): void
    {
        $this->commune('33503', 'Saumos');
        $registered = $this->register('33503', 'Saumos');

        $found = $this->search('Saumos');

        self::assertSame([$registered->id()->toRfc4122()], [$found['33503']->municipalityId]);
        self::assertTrue($found['33503']->registered);
    }

    public function testTheSituationOfTheCommuneInTheEventIsReported(): void
    {
        foreach ([['33001', 'Invitée'], ['33002', 'Participante'], ['33003', 'Clôturée'], ['33004', 'Libre']] as [$code, $name]) {
            $this->commune($code, $name);
            $this->register($code, $name);
        }
        $participations = $this->service(EventParticipationService::class);
        $admin = $this->as($this->w->adminO);
        foreach (['33001', '33002', '33003'] as $code) {
            $municipality = $this->em->getRepository(Municipality::class)->findOneBy(['inseeCode' => $code]) ?? self::fail();
            $participations->invite($admin, new InviteMunicipalityCommand($this->event, $municipality->id()->toRfc4122()));
        }
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET status = 'ACTIVE' WHERE municipality_id = (SELECT id FROM municipality WHERE insee_code = '33002')");
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET status = 'CLOSED' WHERE municipality_id = (SELECT id FROM municipality WHERE insee_code = '33003')");

        $found = $this->search('330');

        self::assertSame(InvitationCandidateState::Invited, $found['33001']->state);
        self::assertSame(InvitationCandidateState::Participating, $found['33002']->state);
        self::assertSame(InvitationCandidateState::Closed, $found['33003']->state);
        self::assertSame(InvitationCandidateState::Available, $found['33004']->state);
    }

    public function testAnInactiveReferentialCommuneAndAnUnavailableRegisteredCommuneAreNotOffered(): void
    {
        $this->commune('33001', 'Disparue', active: false);
        $this->commune('33002', 'Suspendue');
        $this->register('33002', 'Suspendue', MunicipalityStatus::Suspended);
        $this->commune('33003', 'Inactive');
        $this->register('33003', 'Inactive', MunicipalityStatus::Inactive);

        self::assertSame([], $this->search('330'), 'introuvable ou indisponible (DM-086)');
    }

    public function testARegisteredCommuneMissingFromTheReferentialIsStillFound(): void
    {
        $this->register('33519', 'Commune historique');

        $found = $this->search('historique');

        self::assertSame(['33519'], array_map('strval', array_keys($found)));
        self::assertTrue($found['33519']->registered);
    }

    public function testTheOrganizingCommuneIsNeverOffered(): void
    {
        $organizer = $this->w->org;
        $this->commune($organizer->inseeCode()->value, 'Organisatrice nationale');

        self::assertSame([], $this->search('Organisatrice'));
        self::assertSame([], $this->search($organizer->inseeCode()->value));
    }

    public function testWildcardsAreLiteralAndAShortTermStartsNoSearch(): void
    {
        $this->commune('33503', 'Saumos');

        self::assertSame([], $this->search('%%'));
        self::assertSame([], $this->search('_a'));
        self::assertTrue($this->service(InvitationCandidateReader::class)->search($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, 'S')->tooShort);
    }

    public function testNoCommercialDataComesWithTheCandidates(): void
    {
        self::assertSame(['municipalityId', 'name', 'inseeCode', 'state', 'registered'], array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(InvitationCandidate::class))->getProperties()));
    }

    public function testASuspendedEventSearchStillWorksForItsOrganizerOnly(): void
    {
        $this->commune('33503', 'Saumos');
        $this->service(EventLifecycleService::class)->close($this->as($this->w->adminO), $this->event);

        $this->expectException(\App\Shared\Domain\OperationNotAllowed::class);
        $this->search('Saumos'); // événement clôturé : invitation refusée par la politique d'accès
    }
}
