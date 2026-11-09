<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours de délivrance directe (LOT 04.3) dans le navigateur simulé : étape « Durée », récapitulatif en lecture seule, validation finale,
 * confirmation, trois rôles habilités, CSRF, erreurs avec saisie conservée. L'événement est recalé autour de la date du jour (vraie horloge)
 * et prolongé jusqu'en 2028 pour tester une heure locale ambiguë (31 octobre 2027).
 */
#[Group('requires-request-schema')]
final class DirectIssuanceFlowTest extends PublicRequestTestCase
{
    private User $instructor;
    private User $manager;
    private User $admin;
    private string $organizerId;

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        [$this->instructor, $this->manager, $this->admin] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 3));
        $this->save(
            $this->instructor,
            $this->manager,
            $this->admin,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->admin, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => '2028-01-01 00:00:00']);
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.'));
    }

    private function url(string $suffix = ''): string
    {
        return '/communes/'.$this->organizerId.'/evenements/'.$this->event->id()->toRfc4122().'/demandes/nouvelle'.$suffix;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function step(string $step, array $values, bool $token = true): void
    {
        $crawler = $this->client->request('GET', $this->url('/'.$step));
        self::assertResponseIsSuccessful('étape '.$step.' inaccessible');
        $fields = $token ? ['_token' => $crawler->filter('input[name=_token]')->attr('value')] : [];
        $this->client->request('POST', $this->url('/'.$step), $fields + $values);
    }

    /** Parcourt les étapes jusqu'à « Durée » (non soumise). */
    private function toDuration(bool $vehicle = false): void
    {
        $this->step('identite', ['last' => 'Martin', 'first' => 'Camille', 'email' => 'camille@exemple.fr']);
        $this->step('situation', ['category' => 'private_person', 'motif' => 'home']);
        $this->step('zones', ['zones' => [$this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->organizerZone->id()->toRfc4122()]]]]);
        $this->step('deplacement', $vehicle ? ['mode' => 'vehicle', 'plates' => ['GH-482-KT']] : ['mode' => 'foot_bike']);
        $this->step('justificatifs', []);
        self::assertResponseRedirects($this->url('/duree'));
    }

    /**
     * @param array<string, string> $duration
     */
    private function toRecap(array $duration = ['duration_mode' => 'H24'], bool $vehicle = false): void
    {
        $this->toDuration($vehicle);
        $this->step('duree', $duration);
        self::assertResponseRedirects($this->url('/recapitulatif'));
    }

    private function slotInSixDays(EventZone $zone): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $zone->id()->toRfc4122())]);
        $managed = $this->em()->find(EventZone::class, $zone->id()) ?? self::fail('Zone absente.');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify('+6 days')->format('Y-m-d');
        $this->save(EventZoneValidity::fromSlot($managed, ValiditySlot::punctual(T::date($date), T::time('09:00'), T::time('17:00')), F::now()));
    }

    /**
     * @return list<int>
     */
    private function counts(): array
    {
        return [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision'), $this->rows('audit_log WHERE action LIKE \'REQUEST_%\''), $this->rows('request_idempotency_key')];
    }

    // ------------------------------------------------------------------ Étape Durée

    public function testTheDurationStepOffersTheFiveModesWithoutAStartDateAndHidesTheCustomEnd(): void
    {
        $this->as($this->manager);
        $this->toDuration();
        $crawler = $this->client->request('GET', $this->url('/duree'));

        self::assertResponseIsSuccessful();
        self::assertSame(['H24', 'H48', 'H72', 'CUSTOM', 'UNTIL_CLOSURE'], $crawler->filter('input[name=duration_mode]')->each(static fn ($n): string => (string) $n->attr('value')));
        self::assertNotNull($crawler->filter('input[name=duration_mode][value=H24]')->attr('checked'));
        self::assertNotNull($crawler->filter('[data-when="duration_mode=CUSTOM"]')->attr('hidden'), 'la fin personnalisée est masquée tant que le mode n\'est pas choisi');
        self::assertSelectorNotExists('input[name*=start]');
        self::assertSelectorTextContains('main', 'Heure de l’événement (Europe/Paris)');
        self::assertSelectorTextContains('main', 'elle commence à la validation');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'aucun mode' => [[], 'Choisissez la durée'];
        yield 'fin personnalisée absente' => [['duration_mode' => 'CUSTOM'], 'date et l\'heure de fin'];
        yield 'fin passée' => [['duration_mode' => 'CUSTOM', 'duration_date' => '2020-01-01', 'duration_time' => '10:00'], 'postérieure à la décision'];
        yield 'au-delà de l\'événement' => [['duration_mode' => 'CUSTOM', 'duration_date' => '2028-02-01', 'duration_time' => '10:00'], 'dépasser la fin prévue'];
        yield 'heure locale inexistante' => [['duration_mode' => 'CUSTOM', 'duration_date' => '2027-03-28', 'duration_time' => '02:30'], 'n\'existe pas'];
        yield 'mode inconnu' => [['duration_mode' => 'H96'], 'Durée d\'autorisation inconnue'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidDurations')]
    public function testAnInvalidDurationIsAFormErrorThatKeepsTheInput(array $values, string $message): void
    {
        $this->as($this->instructor);
        $this->toDuration();
        $this->step('duree', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertSame(0, $this->rows('request_permit_request'));
        if (isset($values['duration_date'])) {
            self::assertSame($values['duration_date'], $this->client->getCrawler()->filter('input[name=duration_date]')->attr('value'));
        }
    }

    public function testAnAmbiguousLocalEndAsksForTheOccurrenceThenAcceptsTheChoice(): void
    {
        $this->as($this->instructor);
        $this->toDuration();
        $values = ['duration_mode' => 'CUSTOM', 'duration_date' => '2027-10-31', 'duration_time' => '02:30'];
        $this->step('duree', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('select[name=duration_occurrence]');
        self::assertStringContainsString('deux fois', (string) $this->client->getResponse()->getContent());

        $this->step('duree', $values + ['duration_occurrence' => 'second']);
        self::assertResponseRedirects($this->url('/recapitulatif'));
        $this->client->request('GET', $this->url('/recapitulatif'));
        self::assertSelectorTextContains('main', 'Jusqu’à une date de fin choisie');
    }

    public function testAZoneIncompatibleWithTheDurationBlocksTheStepAndIsNamed(): void
    {
        $this->slotInSixDays($this->organizerZone);
        $this->as($this->instructor);
        $this->toDuration();
        $this->step('duree', ['duration_mode' => 'H24']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Route départementale 107', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertStringContainsString('aucune période d’accès', html_entity_decode((string) $this->client->getResponse()->getContent()));

        $this->step('duree', ['duration_mode' => 'UNTIL_CLOSURE']);
        self::assertResponseRedirects($this->url('/recapitulatif'));
    }

    // ------------------------------------------------------------------ Récapitulatif (lecture seule)

    public function testTheRecapShowsTheWholeIssuanceWithoutWritingAnything(): void
    {
        $this->as($this->manager);
        $this->toRecap(['duration_mode' => 'H48'], vehicle: true);
        $before = $this->counts();
        $this->client->request('GET', $this->url('/recapitulatif'));

        self::assertResponseIsSuccessful();
        foreach ([$this->event->name(), 'Martin', 'Camille', 'Accès au domicile', 'En véhicule', 'GH-482-KT', 'Route départementale 107', '48 heures', 'Période globale prévisionnelle', 'Limites d’accès', 'directement', 'sans instruction ultérieure'] as $expected) {
            self::assertStringContainsString($expected, html_entity_decode((string) $this->client->getResponse()->getContent()), $expected);
        }
        self::assertSelectorTextContains('main', 'Délivrer l’autorisation');
        self::assertSelectorExists('.cp-reqform__nav button[type=submit]');
        self::assertSame($before, $this->counts());
        self::assertSame([0, 0, 0, 0], $before, 'la prévisualisation n\'écrit rien');
    }

    public function testAZoneThatBecameIncompatibleSendsBackToTheDurationWithAnExplanationAndWritesNothing(): void
    {
        $this->as($this->instructor);
        $this->toRecap(['duration_mode' => 'H24']);
        $token = (string) $this->client->request('GET', $this->url('/recapitulatif'))->filter('input[name=_token]')->attr('value');
        $this->slotInSixDays($this->organizerZone); // la zone change entre la durée et la validation

        // Le récapitulatif n'est plus atteignable : retour à la durée, avec la zone nommée (jamais de redirection muette).
        $this->client->request('GET', $this->url('/recapitulatif'));
        self::assertResponseRedirects($this->url('/duree'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Route départementale 107');
        self::assertSelectorTextContains('main', 'À vérifier avant de continuer');

        // Un POST forgé de la validation finale est rejoué côté serveur : refus, saisie conservée, rien d'écrit.
        $this->client->request('POST', $this->url('/recapitulatif'), ['_token' => $token, 'privacy' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Route départementale 107', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertSame([0, 0, 0, 0], $this->counts());
        $this->client->request('GET', $this->url('/identite'));
        self::assertSame('Martin', $this->client->getCrawler()->filter('input[name=last]')->attr('value'));
    }

    // ------------------------------------------------------------------ Validation finale et confirmation

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        yield 'INSTRUCTOR' => ['instructor'];
        yield 'EVENT_MANAGER sans INSTRUCTOR' => ['manager'];
        yield 'MUNICIPALITY_ADMIN sans INSTRUCTOR' => ['admin'];
    }

    #[DataProvider('roles')]
    public function testEachAuthorisedRoleIssuesAnApprovedAuthorizationAndSeesItsConfirmation(string $role): void
    {
        $agent = $this->{$role};
        $this->as($agent);
        $this->toRecap(['duration_mode' => 'H72']);
        $this->step('recapitulatif', ['privacy' => '1']);

        $requestId = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request'))->toRfc4122();
        $issued = '/communes/'.$this->organizerId.'/delivrances/'.$requestId;
        self::assertResponseRedirects($issued);
        self::assertStringNotContainsString('Martin', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['APPROVED'], $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM request_permit_request_municipality'));
        self::assertSame([1, 1], [$this->rows('request_permit_request_decision'), $this->rows('request_permit_request')]);

        foreach ([1, 2] as $attempt) { // rafraîchissement : relu en base, même résultat
            $this->client->request('GET', $issued);
            self::assertResponseIsSuccessful('affichage '.$attempt);
            self::assertSelectorTextContains('main', 'Approuvée');
            self::assertSelectorTextContains('main', '72 heures');
            self::assertSelectorTextContains('main', 'Camille Martin');
            self::assertSelectorTextNotContains('main', 'En attente');
        }
        if ('instructor' !== $role) {
            self::assertSelectorNotExists('a[href$="/demandes/'.$requestId.'"]', 'aucun lien vers la consultation pour un rôle sans INSTRUCTOR');
            $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes/'.$requestId);
            self::assertResponseStatusCodeSame(403);
        } else {
            self::assertSelectorExists('a[href$="/demandes/'.$requestId.'"]');
        }
    }

    public function testCsrfIsRequiredOnTheDurationStepAndOnTheFinalValidation(): void
    {
        $this->as($this->instructor);
        $this->toDuration();
        $this->step('duree', ['duration_mode' => 'H24'], token: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'session a expiré');
        $this->step('duree', ['duration_mode' => 'H24']);

        $this->step('recapitulatif', ['privacy' => '1'], token: false);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', $this->url('/recapitulatif'), ['_token' => 'forge', 'privacy' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([0, 0, 0, 0], $this->counts());
    }

    public function testAnEventOrParticipationThatStopsBeingOperationalBlocksTheFinalValidation(): void
    {
        $this->as($this->instructor);
        $this->toRecap();
        $token = (string) $this->client->request('GET', $this->url('/recapitulatif'))->filter('input[name=_token]')->attr('value');
        $this->sql('UPDATE event_event SET status = \'SUSPENDED\'');
        $this->as($this->instructor);
        $this->client->request('POST', $this->url('/recapitulatif'), ['_token' => $token, 'privacy' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([0, 0, 0, 0], $this->counts());
    }

    public function testNothingInTheUrlsRevealsThePersonalData(): void
    {
        $this->as($this->manager);
        $this->toRecap();
        $this->step('recapitulatif', ['privacy' => '1']);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertDoesNotMatchRegularExpression('/martin|camille|exemple/i', $location);
        self::assertMatchesRegularExpression('#/delivrances/[0-9a-f-]{36}$#', parse_url($location, \PHP_URL_PATH) ?: '');
    }

    public function testThePublicFormHasNoDurationStep(): void
    {
        $this->client->request('GET', $this->base.'/duree');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('input[name=duration_mode]');
    }
}
