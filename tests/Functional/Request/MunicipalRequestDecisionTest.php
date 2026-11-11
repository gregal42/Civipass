<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Interface d'instruction (LOT 04.2) de bout en bout : liste filtrée, fiche, approbation en deux temps, refus, erreurs, CSRF, double envoi et
 * permissions. L'événement est recalé autour de la date du jour : l'application utilise la vraie horloge.
 */
#[Group('requires-request-schema')]
final class MunicipalRequestDecisionTest extends PublicRequestTestCase
{
    private User $instructorO;
    private User $instructorP;
    private User $adminO;
    private User $managerO;
    private User $foreign;
    private Municipality $foreignCommune;
    private string $requestId;
    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['request_permit_request_decision', 'request_refusal_reason'])) {
            self::markTestSkipped('Schéma de décision non migré (make test-db).');
        }
        static::getContainer()->get(RefusalReasonInitializer::class)->initialize();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = UNHEX(:id)', ['l' => 'Rue des Tilleuls', 'id' => $this->hex($this->organizerZone->id())]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = UNHEX(:id)', ['l' => 'Quai du Port', 'id' => $this->hex($this->partnerZone->id())]);
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->foreignCommune = MunicipalityBuilder::aMunicipality()->withInseeCode('13055')->build();
        [$this->instructorO, $this->instructorP, $this->adminO, $this->managerO, $this->foreign] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 5));
        $this->save(
            $this->foreignCommune,
            $this->instructorO,
            $this->instructorP,
            $this->adminO,
            $this->managerO,
            $this->foreign,
            MembershipBuilder::aMembership($this->instructorO, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->instructorP, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->adminO, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->managerO, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->foreign, $this->foreignCommune)->withRoles(Role::Instructor)->build(),
        );
        $this->requestId = $this->deposit([$this->organizerZone, $this->partnerZone]);
    }

    /**
     * @param list<EventZone> $zones
     */
    private function deposit(array $zones, string $last = 'Martin'): string
    {
        $submitted = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            $last,
            'Camille',
            null,
            'camille@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::FootBike,
            [],
            array_map(static fn ($z): string => $z->id()->toRfc4122(), $zones),
            true,
        ), sprintf('decision-ui-%016d', ++$this->keys));
        $this->em()->clear();

        return $submitted->requestId;
    }

    private function hex(Uuid $id): string
    {
        return str_replace('-', '', $id->toRfc4122());
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.'));
    }

    private function communeOf(User $user): string
    {
        return match ($user) {
            $this->instructorO, $this->adminO, $this->managerO => $this->organizerParticipation->municipality()->id()->toRfc4122(),
            $this->foreign => $this->foreignCommune->id()->toRfc4122(),
            default => $this->partnerParticipation->municipality()->id()->toRfc4122(),
        };
    }

    private function url(User $user, string $suffix = '', ?string $requestId = null, ?string $commune = null): string
    {
        return '/communes/'.($commune ?? $this->communeOf($user)).'/demandes/'.($requestId ?? $this->requestId).$suffix;
    }

    private function token(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name=_token]')->attr('value');
    }

    /**
     * Approbation complète : formulaire (jeton), récapitulatif, confirmation. Retourne le jeton du récapitulatif.
     *
     * @param array<string, string> $fields
     */
    private function approve(User $user, array $fields, ?string $requestId = null): void
    {
        $this->as($user);
        $url = $this->url($user, '/approuver', $requestId);
        $token = $this->token($url);
        $this->client->request('POST', $url, $fields + ['_token' => $token, 'step' => 'review']);
        if (200 !== $this->client->getResponse()->getStatusCode()) {
            return;
        }
        $this->client->request('POST', $url, $fields + ['_token' => $token, 'step' => 'confirm']);
    }

    private function refuse(User $user, string $reason, string $comment = '', ?string $requestId = null): void
    {
        $this->as($user);
        $url = $this->url($user, '/refuser', $requestId);
        $this->client->request('POST', $url, ['_token' => $this->token($url), 'reason' => $reason, 'comment' => $comment]);
    }

    private function decisions(): int
    {
        return $this->rows('request_permit_request_decision');
    }

    private function partStatus(User $user): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM request_permit_request_municipality WHERE request_id = :r AND municipality_id = :m', ['r' => Uuid::fromString($this->requestId)->toBinary(), 'm' => Uuid::fromString($this->communeOf($user))->toBinary()]);
    }

    private function localDate(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify($modifier)->format('Y-m-d');
    }

    // ------------------------------------------------------------------ Liste

    public function testTheListFiltersByTheStatusOfTheMunicipalityPortionOnly(): void
    {
        $this->as($this->instructorO);
        foreach (['', '?statut=en-attente'] as $query) {
            $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes'.$query);
            self::assertSelectorTextContains('main', 'MARTIN Camille');
        }
        $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes?statut=approuvees');
        self::assertSelectorTextNotContains('main', 'MARTIN Camille');
        self::assertSelectorTextContains('main', 'Aucune demande ne correspond');

        $this->approve($this->instructorO, ['mode' => 'H24']);
        self::assertResponseRedirects();
        foreach (['?statut=approuvees' => true, '?statut=en-attente' => false, '?statut=refusees' => false, '' => true] as $query => $visible) {
            $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes'.$query);
            $visible ? self::assertSelectorTextContains('main', 'MARTIN Camille') : self::assertSelectorTextNotContains('main', 'MARTIN Camille');
        }
        // Le statut affiché est celui de la portion de CHAQUE commune : la partenaire attend toujours.
        $this->as($this->instructorP);
        $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorP).'/demandes?statut=en-attente');
        self::assertSelectorTextContains('main', 'MARTIN Camille');
        self::assertSelectorTextContains('main', 'Quai du Port');
        self::assertSelectorTextNotContains('main', 'Rue des Tilleuls');
        $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorP).'/demandes?statut=approuvees');
        self::assertSelectorTextNotContains('main', 'MARTIN Camille');
    }

    public function testTheStatusTabsAreLinksThatKeepTheOtherFiltersAndAnUnknownStatusIsIgnored(): void
    {
        $this->as($this->instructorO);
        $crawler = $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes?q=Martin&statut=refusees');
        $labels = $crawler->filter('.cp-subnav__link')->each(static fn ($n): string => trim($n->text()));
        self::assertSame(['Toutes', 'En attente', 'Approuvées', 'Refusées'], $labels);
        self::assertSame('Refusées', trim($crawler->filter('.cp-subnav__link[aria-current=page]')->text()));
        self::assertStringContainsString('q=Martin', (string) $crawler->filter('.cp-subnav__link')->eq(1)->attr('href'));
        self::assertStringContainsString('statut=en-attente', (string) $crawler->filter('.cp-subnav__link')->eq(1)->attr('href'));

        $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes?statut=<script>');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'MARTIN Camille'); // filtre inconnu : ignoré, jamais exécuté
    }

    public function testThePaginationKeepsTheStatusFilter(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->deposit([$this->organizerZone], 'Dupont'.$i);
        }
        $this->as($this->instructorO);
        $crawler = $this->client->request('GET', '/communes/'.$this->communeOf($this->instructorO).'/demandes?statut=en-attente');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '1–20 sur 22');
        $next = (string) $crawler->filter('a[href*="page=2"]')->first()->attr('href');
        self::assertStringContainsString('statut=en-attente', $next);
        $this->client->request('GET', $next);
        self::assertSelectorTextContains('main', '21–22 sur 22');
    }

    // ------------------------------------------------------------------ Fiche

    public function testAPendingDetailOffersBothActionsForOwnZonesOnly(): void
    {
        $this->as($this->instructorO);
        $this->client->request('GET', $this->url($this->instructorO));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="'.$this->url($this->instructorO, '/approuver').'"]');
        self::assertSelectorExists('a[href="'.$this->url($this->instructorO, '/refuser').'"]');
        self::assertSelectorTextContains('main', 'Rue des Tilleuls');
        self::assertSelectorTextContains('main', 'Toute la durée de l’événement');
        self::assertSelectorTextNotContains('main', 'Quai du Port');
    }

    public function testADecidedDetailIsReadOnlyAndShowsTheDecisionOfTheOwnMunicipalityOnly(): void
    {
        $this->approve($this->instructorO, ['mode' => 'H48']);
        $this->client->followRedirect();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSelectorNotExists('a[href="'.$this->url($this->instructorO, '/approuver').'"]');
        self::assertSelectorNotExists('a[href="'.$this->url($this->instructorO, '/refuser').'"]');
        foreach (['Approuvée', '48 heures', 'Période d’autorisation', $this->em()->find(User::class, $this->instructorO->id())?->lastName() ?? '', 'est approuvée pour toutes les zones'] as $expected) {
            self::assertStringContainsString($expected, $html, $expected);
        }
        self::assertStringNotContainsString('Quai du Port', $html);

        // L'autre commune ne voit ni la décision ni son auteur : sa portion est toujours à traiter.
        $this->as($this->instructorP);
        $this->client->request('GET', $this->url($this->instructorP));
        $partner = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('48 heures', $partner);
        self::assertStringNotContainsString('Approuvée', $partner);
        self::assertSelectorExists('a[href="'.$this->url($this->instructorP, '/approuver').'"]');
    }

    public function testARefusedDetailShowsTheReasonAndTheComment(): void
    {
        $this->refuse($this->instructorO, 'other', 'Le dossier ne relève pas de la commune.');
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Refusée');
        self::assertSelectorTextContains('main', 'Autre');
        self::assertSelectorTextContains('main', 'Le dossier ne relève pas de la commune.');
        self::assertSelectorNotExists('a[href="'.$this->url($this->instructorO, '/refuser').'"]');
    }

    // ------------------------------------------------------------------ Approbation

    /**
     * @return iterable<string, array{array<string, string>, string, bool}>
     */
    public static function durations(): iterable
    {
        yield 'H24' => [['mode' => 'H24'], 'H24', false];
        yield 'H48' => [['mode' => 'H48'], 'H48', false];
        yield 'H72' => [['mode' => 'H72'], 'H72', false];
        yield 'CUSTOM' => [['mode' => 'CUSTOM', 'end_date' => '+3 days', 'end_time' => '18:00'], 'CUSTOM', true];
        yield 'UNTIL_CLOSURE' => [['mode' => 'UNTIL_CLOSURE'], 'UNTIL_CLOSURE', false];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('durations')]
    public function testEachDurationModeGoesThroughTheRecapAndIsConfirmed(array $fields, string $mode, bool $custom): void
    {
        if ($custom) {
            $fields['end_date'] = $this->localDate($fields['end_date']);
        }
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $token = $this->token($url);
        self::assertSelectorExists('input[name=mode][value=H24]');
        self::assertSelectorTextNotContains('main', 'début-fictif');

        $this->client->request('POST', $url, $fields + ['_token' => $token, 'step' => 'review']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Récapitulatif');
        self::assertSelectorTextContains('main', 'Toutes les zones demandées');
        self::assertSelectorTextContains('main', 'Rue des Tilleuls');
        self::assertSelectorTextContains('main', 'Période d’autorisation');
        self::assertSame(0, $this->decisions(), 'le récapitulatif n\'écrit rien');

        $this->client->request('POST', $url, $fields + ['_token' => $token, 'step' => 'confirm']);
        self::assertResponseRedirects($this->url($this->instructorO));
        self::assertSame('APPROVED', $this->partStatus($this->instructorO));
        self::assertSame('PENDING', $this->partStatus($this->instructorP));
        self::assertSame($mode, $this->em()->getConnection()->fetchOne('SELECT duration_mode FROM request_permit_request_decision'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'est approuvée pour toutes les zones');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'fin absente' => [['mode' => 'CUSTOM'], 'date et l\'heure de fin'];
        yield 'date invalide' => [['mode' => 'CUSTOM', 'end_date' => 'demain', 'end_time' => '10:00'], 'Date invalide'];
        yield 'mode inconnu' => [['mode' => 'H96'], 'Durée d\'autorisation inconnue'];
        yield 'fin sur une durée fixe' => [['mode' => 'H24', 'end_date' => '2030-01-01', 'end_time' => '10:00'], 'durée personnalisée'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('invalidDurations')]
    public function testAnInvalidDurationIsAFormErrorThatKeepsTheInput(array $fields, string $message): void
    {
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $this->client->request('POST', $url, $fields + ['_token' => $this->token($url), 'step' => 'review']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertSelectorExists('.cp-error-summary');
        self::assertSame(0, $this->decisions());
    }

    public function testACustomEndInThePastOrBeyondTheEventIsRefusedWithTheFormKept(): void
    {
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        foreach ([['-2 days', 'postérieure'], ['+30 days', 'dépasser la fin prévue']] as [$modifier, $message]) {
            $fields = ['mode' => 'CUSTOM', 'end_date' => $this->localDate($modifier), 'end_time' => '12:00'];
            $crawler = $this->client->request('POST', $url, $fields + ['_token' => $this->token($url), 'step' => 'review']);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString($message, (string) $this->client->getResponse()->getContent());
            self::assertSame($fields['end_date'], $crawler->filter('input[name=end_date]')->attr('value'));
            self::assertSame('12:00', $crawler->filter('input[name=end_time]')->attr('value'));
            self::assertNotNull($crawler->filter('input[name=mode][value=CUSTOM]')->attr('checked'));
        }
        self::assertSame(0, $this->decisions());
    }

    public function testAnApprovalIncompatibleWithAZoneIsRefusedWithAReadableMessageAndTheSelectionKept(): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', ['id' => $this->hex($this->organizerZone->id())]);
        $zone = $this->em()->find(EventZone::class, $this->organizerZone->id()) ?? self::fail('Zone absente.');
        $this->save(EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($this->localDate('+6 days')), T::time('09:00'), T::time('17:00')), F::now()));

        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $token = $this->token($url);
        $this->client->request('POST', $url, ['mode' => 'H48', '_token' => $token, 'step' => 'confirm']);

        self::assertResponseStatusCodeSame(422);
        $html = html_entity_decode((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Rue des Tilleuls', $html);
        self::assertStringContainsString('aucune période d\'accès dans la durée d\'autorisation choisie', $html);
        self::assertSelectorNotExists('.cp-alert--success');
        self::assertNotNull($this->client->getCrawler()->filter('input[name=mode][value=H48]')->attr('checked'));
        self::assertSame(0, $this->decisions());
        self::assertSame('PENDING', $this->partStatus($this->instructorO));

        // Une durée assez longue (jusqu'à clôture) est acceptée avec la même zone.
        $this->client->request('POST', $url, ['mode' => 'UNTIL_CLOSURE', '_token' => $token, 'step' => 'confirm']);
        self::assertResponseRedirects();
        self::assertSame('APPROVED', $this->partStatus($this->instructorO));
    }

    public function testAMissingOrForgedCsrfTokenWritesNothing(): void
    {
        $this->as($this->instructorO);
        $approve = $this->url($this->instructorO, '/approuver');
        $refuse = $this->url($this->instructorO, '/refuser');
        foreach ([[], ['_token' => 'forge']] as $token) {
            $this->client->request('POST', $approve, $token + ['mode' => 'H24', 'step' => 'confirm']);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Le formulaire a expiré', (string) $this->client->getResponse()->getContent());
            $this->client->request('POST', $refuse, $token + ['reason' => 'duplicate']);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Le formulaire a expiré', (string) $this->client->getResponse()->getContent());
        }
        // Un jeton valide pour une AUTRE demande n'est pas accepté.
        $other = $this->deposit([$this->organizerZone], 'Autre');
        $this->as($this->instructorO);
        $foreignToken = $this->token($this->url($this->instructorO, '/approuver', $other));
        $this->client->request('POST', $approve, ['mode' => 'H24', 'step' => 'confirm', '_token' => $foreignToken]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->decisions());
        self::assertSame('PENDING', $this->partStatus($this->instructorO));
    }

    public function testASecondSubmissionOfTheSameConfirmationCreatesNoSecondDecision(): void
    {
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $fields = ['mode' => 'H24', '_token' => $this->token($url), 'step' => 'confirm'];

        $this->client->request('POST', $url, $fields);
        self::assertResponseRedirects($this->url($this->instructorO));
        $this->client->request('POST', $url, $fields); // double clic, rejeu du navigateur
        self::assertResponseRedirects($this->url($this->instructorO));
        $this->client->followRedirect();

        self::assertSame(1, $this->decisions());
        self::assertSame(1, $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\''));
        self::assertSelectorTextContains('main', 'déjà fait l’objet d’une décision');
    }

    public function testTheApprovalFormIsInaccessibleOnceDecided(): void
    {
        $this->approve($this->instructorO, ['mode' => 'H24']);
        foreach (['/approuver', '/refuser'] as $suffix) {
            $this->client->request('GET', $this->url($this->instructorO, $suffix));
            self::assertResponseRedirects($this->url($this->instructorO));
            $this->client->followRedirect();
            self::assertSelectorTextContains('main', 'déjà fait l’objet d’une décision');
        }
    }

    // ------------------------------------------------------------------ Refus

    public function testARefusalWithAValidReasonIsRecordedForTheOwnPortionOnly(): void
    {
        $this->as($this->instructorO);
        $crawler = $this->client->request('GET', $this->url($this->instructorO, '/refuser'));
        $reasons = $crawler->filter('input[name=reason]')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertSame(['ineligible_beneficiary', 'reason_not_justified', 'documents_insufficient', 'zone_not_concerned', 'duplicate', 'inconsistent_information', 'other'], $reasons);
        self::assertSelectorTextContains('main', 'toutes les zones demandées');

        $this->refuse($this->instructorO, 'duplicate', '  ');
        self::assertResponseRedirects($this->url($this->instructorO));
        self::assertSame('REFUSED', $this->partStatus($this->instructorO));
        self::assertSame('PENDING', $this->partStatus($this->instructorP));
        self::assertSame('Demande en doublon', $this->em()->getConnection()->fetchOne('SELECT refusal_reason_label FROM request_permit_request_decision'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'est refusée pour toutes les zones');
    }

    public function testOnlyActiveReasonsAreOffered(): void
    {
        $this->sql('UPDATE request_refusal_reason SET active = 0 WHERE code = \'duplicate\'');
        $this->as($this->instructorO);
        $crawler = $this->client->request('GET', $this->url($this->instructorO, '/refuser'));

        self::assertCount(6, $crawler->filter('input[name=reason]'));
        self::assertSelectorNotExists('input[name=reason][value=duplicate]');
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function invalidRefusals(): iterable
    {
        yield 'sans motif' => ['', '', false];
        yield 'motif inconnu' => ['inexistant', '', false];
        yield 'motif inactif' => ['duplicate', '', true];
        yield '« Autre » sans commentaire' => ['other', '', false];
        yield 'commentaire trop long' => ['duplicate', str_repeat('x', 501), false];
    }

    #[DataProvider('invalidRefusals')]
    public function testAnInvalidRefusalIsAFormErrorThatKeepsTheInput(string $reason, string $comment, bool $deactivate): void
    {
        if ($deactivate) {
            $this->sql('UPDATE request_refusal_reason SET active = 0 WHERE code = :code', ['code' => $reason]);
        }
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/refuser');
        $crawler = $this->client->request('POST', $url, ['_token' => $this->token($url), 'reason' => $reason, 'comment' => $comment]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.cp-error-summary');
        self::assertSame($comment, trim($crawler->filter('textarea[name=comment]')->text()));
        self::assertSame(0, $this->decisions());
        self::assertSame('PENDING', $this->partStatus($this->instructorO));
    }

    public function testARefusalIsImpossibleAfterAnApprovalAndTheOtherWayRound(): void
    {
        $this->approve($this->instructorO, ['mode' => 'H24']);
        $url = $this->url($this->instructorO, '/refuser');
        $this->client->request('POST', $url, ['_token' => 'whatever', 'reason' => 'duplicate']);
        self::assertSame('APPROVED', $this->partStatus($this->instructorO));
        self::assertSame(1, $this->decisions());

        $this->refuse($this->instructorP, 'duplicate');
        $this->client->request('POST', $this->url($this->instructorP, '/approuver'), ['mode' => 'H24', 'step' => 'confirm', '_token' => 'x']);
        self::assertSame('REFUSED', $this->partStatus($this->instructorP));
        self::assertSame(2, $this->decisions());
    }

    // ------------------------------------------------------------------ Permissions

    public function testAnonymousUsersAreSentToTheLogin(): void
    {
        foreach (['/approuver', '/refuser'] as $suffix) {
            $this->client->request('GET', $this->url($this->instructorO, $suffix));
            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testAdministratorOrEventManagerWithoutTheInstructorRoleGetsA403EverywhereAndWritesNothing(): void
    {
        foreach ([$this->adminO, $this->managerO] as $user) {
            $this->as($user);
            foreach (['/approuver', '/refuser'] as $suffix) {
                $this->client->request('GET', $this->url($user, $suffix));
                self::assertResponseStatusCodeSame(403);
                $this->client->request('POST', $this->url($user, $suffix), ['mode' => 'H24', 'step' => 'confirm', 'reason' => 'duplicate', '_token' => 'x']);
                self::assertResponseStatusCodeSame(403);
            }
        }
        self::assertSame(0, $this->decisions());
    }

    public function testForeignMunicipalitiesAndForgedIdentifiersGetTheUniform404(): void
    {
        $onlyOrganizer = $this->deposit([$this->organizerZone], 'Seule');
        $cases = [
            'instructeur étranger, commune forgée' => [$this->foreign, $this->url($this->foreign, '/approuver', null, $this->communeOf($this->instructorO))],
            'instructeur partenaire, commune forgée' => [$this->instructorP, $this->url($this->instructorP, '/refuser', null, $this->communeOf($this->instructorO))],
            'instructeur partenaire, dossier sans part chez lui' => [$this->instructorP, $this->url($this->instructorP, '/approuver', $onlyOrganizer)],
            'dossier inexistant' => [$this->instructorO, $this->url($this->instructorO, '/refuser', Uuid::v7()->toRfc4122())],
            'instructeur étranger, sa commune, dossier d\'ailleurs' => [$this->foreign, $this->url($this->foreign, '/approuver')],
        ];
        foreach ($cases as $label => [$user, $path]) {
            $this->as($user);
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $label.' (GET)');
            $this->client->request('POST', $path, ['mode' => 'H24', 'step' => 'confirm', 'reason' => 'duplicate', '_token' => 'x']);
            self::assertResponseStatusCodeSame(404, $label.' (POST)');
        }
        self::assertSame(0, $this->decisions());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blockedStates(): iterable
    {
        yield 'participation SUSPENDED' => ['UPDATE event_participation SET status = \'SUSPENDED\' WHERE municipality_id = UNHEX(:m)', 'participation de votre commune'];
        yield 'participation CLOSED' => ['UPDATE event_participation SET status = \'CLOSED\' WHERE municipality_id = UNHEX(:m)', 'participation de votre commune'];
        yield 'événement suspendu' => ['UPDATE event_event SET status = \'SUSPENDED\' WHERE :m <> \'\'', 'n’est pas ouvert aux décisions'];
        yield 'commune suspendue' => ['UPDATE municipality SET status = \'SUSPENDED\' WHERE id = UNHEX(:m)', 'commune est suspendue'];
    }

    #[DataProvider('blockedStates')]
    public function testADecisionIsImpossibleWithAnExplanationWhenTheContextNoLongerAllowsIt(string $sql, string $explanation): void
    {
        $this->sql($sql, ['m' => str_replace('-', '', $this->communeOf($this->instructorO))]);
        $this->as($this->instructorO);

        // La fiche reste consultable, sans action, avec la raison.
        $this->client->request('GET', $this->url($this->instructorO));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $explanation);
        self::assertSelectorNotExists('a[href="'.$this->url($this->instructorO, '/approuver').'"]');
        self::assertSelectorNotExists('a[href="'.$this->url($this->instructorO, '/refuser').'"]');

        // Les formulaires redirigent vers la fiche ; un POST forgé n'écrit rien.
        foreach (['/approuver', '/refuser'] as $suffix) {
            $this->client->request('GET', $this->url($this->instructorO, $suffix));
            self::assertResponseRedirects($this->url($this->instructorO));
            $this->client->request('POST', $this->url($this->instructorO, $suffix), ['mode' => 'H24', 'step' => 'confirm', 'reason' => 'duplicate', '_token' => 'x']);
            self::assertResponseRedirects($this->url($this->instructorO));
        }
        self::assertSame(0, $this->decisions());
    }

    /** LOT 05.6.2 : un événement CLOS ne sert plus la fiche ni les écrans de décision (410 neutre, aucune donnée), il ne s'explique plus. */
    public function testAClosedEventNoLongerServesTheDossierNorTheDecisionScreens(): void
    {
        $this->sql('UPDATE event_event SET status = \'CLOSED\' WHERE :m <> \'\'', ['m' => 'x']);
        $this->as($this->instructorO);

        foreach (['', '/approuver', '/refuser'] as $suffix) {
            $this->client->request('GET', $this->url($this->instructorO, $suffix));
            self::assertResponseStatusCodeSame(410);
            if ('' === $suffix) {
                continue; // la fiche n'accepte que GET
            }
            $this->client->request('POST', $this->url($this->instructorO, $suffix), ['mode' => 'H24', 'step' => 'confirm', 'reason' => 'duplicate', '_token' => 'x']);
            self::assertResponseStatusCodeSame(410);
        }
        self::assertSame(0, $this->decisions());
    }

    // ------------------------------------------------------------------ Récapitulatif : compatibilité des zones (lecture seule)

    private function specificSlotInSixDays(): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', ['id' => $this->hex($this->organizerZone->id())]);
        $zone = $this->em()->find(EventZone::class, $this->organizerZone->id()) ?? self::fail('Zone absente.');
        $this->save(EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($this->localDate('+6 days')), T::time('09:00'), T::time('17:00')), F::now()));
    }

    public function testTheRecapNamesTheIncompatibleZoneAndOffersNoConfirmationWithoutWritingAnything(): void
    {
        $this->specificSlotInSixDays();
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $crawler = $this->client->request('POST', $url, ['mode' => 'H48', '_token' => $this->token($url), 'step' => 'review']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Approbation impossible avec cette durée');
        self::assertSelectorTextContains('main', 'Rue des Tilleuls');
        self::assertSelectorTextContains('main', 'aucune période d’accès dans la durée d’autorisation choisie');
        self::assertCount(0, $crawler->filter('form#approve-confirm button[type=submit]'), 'aucune confirmation possible depuis cette page');
        self::assertSelectorExists('form#approve-confirm button[aria-disabled=true]');
        self::assertSame(0, $this->decisions());
        self::assertSame(0, $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\''));

        // Une durée compatible rouvre la confirmation depuis le récapitulatif.
        $crawler = $this->client->request('POST', $url, ['mode' => 'UNTIL_CLOSURE', '_token' => $this->token($url), 'step' => 'review']);
        self::assertSelectorTextNotContains('main', 'Approbation impossible avec cette durée');
        self::assertCount(1, $crawler->filter('form#approve-confirm button[type=submit]'));
    }

    public function testAForgedConfirmationStillGoesThroughEveryTransactionalCheck(): void
    {
        $this->specificSlotInSixDays();
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $this->client->request('POST', $url, ['mode' => 'H48', '_token' => $this->token($url), 'step' => 'confirm']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->decisions());
        self::assertSame('PENDING', $this->partStatus($this->instructorO));
    }

    public function testTheRecapOnlyShowsTheZonesOfTheOwnMunicipalityEvenWhenAnotherOneIsIncompatible(): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', ['id' => $this->hex($this->partnerZone->id())]); // zone de la partenaire sans créneau
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $this->client->request('POST', $url, ['mode' => 'H24', '_token' => $this->token($url), 'step' => 'review']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Approbation impossible');
        self::assertSelectorTextNotContains('main', 'Quai du Port');
    }

    // ------------------------------------------------------------------ Aucune donnée de formulaire dans l'URL

    public function testTheRecapEditLinkAndTheFormNeverCarryTheInputInTheUrl(): void
    {
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $fields = ['mode' => 'CUSTOM', 'end_date' => $this->localDate('+3 days'), 'end_time' => '18:00'];
        $crawler = $this->client->request('POST', $url, $fields + ['_token' => $this->token($url), 'step' => 'review']);
        self::assertResponseIsSuccessful();
        foreach ($crawler->filter('a')->each(static fn ($a): string => (string) $a->attr('href')) as $href) {
            self::assertStringNotContainsString('end_date', $href);
            self::assertStringNotContainsString('mode=', $href);
        }

        // « Modifier la durée » : POST, valeurs conservées.
        $crawler = $this->client->submit($crawler->filter('input[name=step][value=edit]')->closest('form')->form());
        self::assertResponseIsSuccessful();
        self::assertSame($fields['end_date'], $crawler->filter('input[name=end_date]')->attr('value'));
        self::assertNotNull($crawler->filter('input[name=mode][value=CUSTOM]')->attr('checked'));

        // Une saisie passée en paramètres d'URL est ignorée.
        $crawler = $this->client->request('GET', $url.'?mode=H72&end_date=2030-01-01');
        self::assertNotNull($crawler->filter('input[name=mode][value=H24]')->attr('checked'));
        self::assertSame('', (string) $crawler->filter('input[name=end_date]')->attr('value'));
    }

    public function testATechnicalConflictRedisplaysTheFormWithTheKeptInputAndNothingInTheUrl(): void
    {
        $participation = $this->hex($this->organizerParticipation->id());
        $this->as($this->instructorO);
        $approve = $this->url($this->instructorO, '/approuver');
        $refuse = $this->url($this->instructorO, '/refuser');
        $approveToken = $this->token($approve);
        $refuseToken = $this->token($refuse);

        // Un autre traitement tient le verrou de la participation : le délai d'attente (5 s) s'épuise, rien n'est écrit.
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM event_participation WHERE id = UNHEX(:id) FOR UPDATE', ['id' => $participation]);
        try {
            $crawler = $this->client->request('POST', $approve, ['mode' => 'H48', '_token' => $approveToken, 'step' => 'confirm']);
            self::assertResponseStatusCodeSame(409);
            self::assertSelectorTextContains('main', 'Un autre traitement est en cours');
            self::assertNotNull($crawler->filter('input[name=mode][value=H48]')->attr('checked'));
            self::assertSame($approve, parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
            self::assertNotSame('', (string) $crawler->filter('input[name=_token]')->attr('value'));

            $crawler = $this->client->request('POST', $refuse, ['reason' => 'duplicate', 'comment' => 'À garder', '_token' => $refuseToken]);
            self::assertResponseStatusCodeSame(409);
            self::assertSelectorTextContains('main', 'Un autre traitement est en cours');
            self::assertNotNull($crawler->filter('input[name=reason][value=duplicate]')->attr('checked'));
            self::assertSame('À garder', trim($crawler->filter('textarea[name=comment]')->text()));
        } finally {
            $other->rollBack();
        }
        self::assertSame(0, $this->decisions());
        self::assertSame('PENDING', $this->partStatus($this->instructorO));
    }

    public function testTheApprovalFormsOptOutOfTurboBecauseTheirPostsAnswerWithAPageNotARedirect(): void
    {
        // Régression de recette : Turbo rejette une réponse 200 à un POST (« Form responses must redirect ») ; seuls les formulaires dont le POST
        // renvoie une page (récapitulatif, retour au formulaire) doivent être chargés normalement.
        $this->as($this->instructorO);
        $url = $this->url($this->instructorO, '/approuver');
        $crawler = $this->client->request('GET', $url);
        self::assertSame('false', $crawler->filter('form#approve-form')->attr('data-turbo'));
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', $url, ['mode' => 'H24', '_token' => $token, 'step' => 'review']);
        self::assertSame('false', $crawler->filter('form#approve-confirm')->attr('data-turbo'));
        self::assertSame('false', $crawler->filter('input[name=step][value=edit]')->closest('form')->attr('data-turbo'));
    }
}
