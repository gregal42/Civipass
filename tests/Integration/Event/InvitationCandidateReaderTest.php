<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\EventDetailReader;
use App\Event\Application\InvitationCandidateReader;
use App\Event\Application\Read\InvitationCandidate;
use App\Event\Application\Read\InvitationCandidateState;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Recherche d'une commune à inviter et identifiants de participation de la fiche (MySQL de test) : droits, isolation,
 * situation des communes, aucune donnée commerciale.
 */
#[Group('requires-event-schema')]
final class InvitationCandidateReaderTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private string $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->event = $this->service(EventCreationService::class)->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))))->eventId;
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function municipality(string $name, string $insee, MunicipalityStatus $status = MunicipalityStatus::Active): Municipality
    {
        $municipality = new Municipality($name, InseeCode::fromString($insee), EventFixtures::now());
        if (MunicipalityStatus::Active !== $status) {
            $municipality->changeStatus($status, EventFixtures::now());
        }
        $this->save($municipality);

        return $municipality;
    }

    private function reader(): InvitationCandidateReader
    {
        return $this->service(InvitationCandidateReader::class);
    }

    /**
     * @return array<string, InvitationCandidateState>
     */
    private function search(string $term, ?User $user = null): array
    {
        $result = [];
        foreach ($this->reader()->search($this->as($user ?? $this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, $term)->items as $candidate) {
            $result[$candidate->name] = $candidate->state;
        }

        return $result;
    }

    public function testSearchByNameAndByInseePrefixOnlyReturnsActiveMunicipalitiesExceptTheOrganizer(): void
    {
        $this->municipality('Saumos', '33519');
        $this->municipality('Saumaise', '33520');
        $this->municipality('Saumur-Inactive', '33521', MunicipalityStatus::Inactive);
        $this->municipality('Saumery-Suspendue', '33522', MunicipalityStatus::Suspended);

        self::assertSame(['Saumaise', 'Saumos'], array_keys($this->search('Saum')), 'tri par nom, actives seulement');
        self::assertSame(['Saumaise'], array_keys($this->search('33520')), 'code INSEE');
        // Le jeu de test nomme ses communes « Commune 1NNNN » (compteur statique) : un nom peut contenir « 335 » selon l'ordre
        // d'exécution de la suite. On ne retient que les communes de ce test.
        $prefixed = array_values(array_filter(array_keys($this->search('335')), static fn (string $name): bool => !str_starts_with($name, 'Commune ')));
        self::assertSame(['Saumaise', 'Saumos'], $prefixed, 'préfixe du code INSEE');
        self::assertSame([], $this->search($this->w->org->name()), 'l\'organisatrice n\'est jamais proposée');
        self::assertSame([], $this->search('%%'), 'jokers littéraux');
        self::assertSame([], $this->search('_ab'));
    }

    public function testEachCandidateSaysWhereItStandsInTheEvent(): void
    {
        $participations = $this->service(EventParticipationService::class);
        $invited = $this->municipality('Cand-Invitée', '40001');
        $participating = $this->municipality('Cand-Participante', '40002');
        $closing = $this->municipality('Cand-Clôturée', '40003');
        $this->municipality('Cand-Libre', '40004');
        foreach ([$invited, $participating, $closing] as $municipality) {
            $participations->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $municipality->id()->toRfc4122()));
        }
        $this->em->clear();
        $accepting = $this->em->getConnection()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_participation WHERE municipality_id = ?', [$participating->id()->toBinary()]);
        // L'acceptation passe par un administrateur de la commune ; on force l'état en base pour isoler la lecture.
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET status = 'ACTIVE', activated_at = NOW() WHERE id = UNHEX(?)", [str_replace('-', '', (string) $accepting)]);
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET status = 'CLOSED' WHERE municipality_id = ?", [$closing->id()->toBinary()]);

        $states = $this->search('Cand-');

        self::assertSame([
            'Cand-Clôturée' => InvitationCandidateState::Closed,
            'Cand-Invitée' => InvitationCandidateState::Invited,
            'Cand-Libre' => InvitationCandidateState::Available,
            'Cand-Participante' => InvitationCandidateState::Participating,
        ], $states);
    }

    public function testShortInputAndTheResultLimit(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->municipality(sprintf('Lot-%02d', $i), sprintf('41%03d', $i));
        }

        $short = $this->reader()->search($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, 'L');
        self::assertTrue($short->tooShort);
        self::assertSame([], $short->items);
        $empty = $this->reader()->search($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, '  ');
        self::assertFalse($empty->tooShort);
        self::assertCount(InvitationCandidateReader::MAX_RESULTS, $this->reader()->search($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event, 'Lot-')->items);
    }

    public function testNoCommercialOrManagementDataIsExposed(): void
    {
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(InvitationCandidate::class))->getProperties());

        self::assertSame(['municipalityId', 'name', 'inseeCode', 'state'], $properties);
    }

    public function testOnlyTheOrganizingMunicipalityWithTheRightToInviteMaySearch(): void
    {
        $cases = [
            [$this->w->instructorO, $this->w->org, $this->event],        // rôle sans droit d'inviter
            [$this->w->adminB, $this->w->guestB, $this->event],          // commune sans lien
            [$this->w->adminX, $this->w->org, $this->event],             // URL d'une commune étrangère
            [$this->w->adminO, $this->w->org, Uuid::v7()->toRfc4122()],  // événement forgé
            [$this->w->adminO, $this->w->org, 'not-a-uuid'],
            [$this->w->superAdmin, $this->w->org, $this->event],         // aucun droit communal implicite
            [$this->w->adminO, $this->w->guestB, $this->event],          // commune dont l'utilisateur n'est pas membre
        ];
        foreach ($cases as [$user, $municipality, $event]) {
            try {
                $this->reader()->search($this->as($user), $municipality->id()->toRfc4122(), $event, 'Commune');
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
        self::assertNotEmpty($this->search('Commune', $this->w->managerO), 'le gestionnaire peut inviter (DM-057)');
    }

    // ── Fiche : identifiant de participation et participation gratuite ───────

    public function testTheRecordCarriesOnlyTheViewersOwnParticipationAndFlagsAFreeParticipation(): void
    {
        $participations = $this->service(EventParticipationService::class);
        $participationB = $participations->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->event, $this->w->guestB->id()->toRfc4122()))->participationId;
        $participations->accept($this->as($this->w->adminB), $participationB);
        $reader = $this->service(EventDetailReader::class);

        $participant = $reader->forEvent($this->as($this->w->adminB), $this->w->guestB->id()->toRfc4122(), $this->event);
        self::assertSame($participationB, $participant->ownParticipationId);
        self::assertTrue($participant->freeParticipation, 'participante sans droit d\'utilisation : participation gratuite (DM-086)');

        $organizer = $reader->forEvent($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), $this->event);
        self::assertNull($organizer->ownParticipationId, 'l\'organisatrice n\'agit pas sur sa participation seule');
        self::assertFalse($organizer->freeParticipation);

        $now = EventFixtures::now();
        $this->save(new MunicipalityCommercialAccess($this->em->find(Municipality::class, $this->w->guestB->id()) ?? self::fail(), $this->as($this->w->superAdmin), $now->modify('-30 days'), null, 'Convention', $now->modify('-30 days')));
        self::assertFalse($reader->forEvent($this->as($this->w->adminB), $this->w->guestB->id()->toRfc4122(), $this->event)->freeParticipation, 'avec un droit d\'utilisation : participation non gratuite');
    }
}
