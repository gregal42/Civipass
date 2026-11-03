<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalPagesTestCase;
use App\Tests\Support\UserBuilder;

/**
 * Coordination intercommunale par l'interface (MySQL de test) : recherche et invitation d'une commune, acceptation, communes
 * visibles selon le niveau de la commune (DM-084, DM-090, DM-091), actions sur SA participation. Les écritures passent par
 * EventParticipationService ; ces tests vérifient le câblage HTTP, l'isolation et les permissions.
 */
final class EventCoordinationPageTest extends MunicipalPagesTestCase
{
    private function inviteUrl(Municipality|string $municipality, string $eventId, string $query = ''): string
    {
        return $this->eventUrl($municipality, $eventId).'/inviter'.$query;
    }

    private function communesUrl(Municipality|string $municipality, string $eventId): string
    {
        return $this->eventUrl($municipality, $eventId).'/communes';
    }

    private function actionUrl(Municipality|string $municipality, string $eventId, string $action): string
    {
        return $this->eventUrl($municipality, $eventId).'/participation/'.$action;
    }

    private function municipality(string $name, string $insee, MunicipalityStatus $status = MunicipalityStatus::Active): Municipality
    {
        $municipality = new Municipality($name, InseeCode::fromString($insee), EventFixtures::now());
        if (MunicipalityStatus::Active !== $status) {
            $municipality->changeStatus($status, EventFixtures::now());
        }
        $this->em()->persist($municipality);
        $this->em()->flush();

        return $municipality;
    }

    private function userIn(Municipality $municipality, MunicipalityRole ...$roles): User
    {
        $user = UserBuilder::aUser()->build();
        $this->em()->persist($user);
        $this->em()->persist(MembershipBuilder::aMembership($user, $this->em()->find(Municipality::class, $municipality->id()) ?? self::fail())->withRoles(...$roles)->build());
        $this->em()->flush();

        return $user;
    }

    /** Jeton du formulaire d'une action de participation (la page en porte plusieurs : un par action). */
    private function actionToken(string $page, string $action): string
    {
        $html = $this->get($page);
        self::assertSame(1, preg_match('#action="[^"]*/participation/'.$action.'"[^>]*>.*?name="_token" value="([^"]+)"#s', $html, $m), 'formulaire '.$action.' absent');

        return $m[1];
    }

    private function participationStatus(Municipality $municipality, string $eventId): ?string
    {
        $status = $this->em()->getConnection()->fetchOne('SELECT status FROM event_participation WHERE event_id = ? AND municipality_id = ?', [(string) hex2bin(str_replace('-', '', $eventId)), $municipality->id()->toBinary()]);

        return false === $status ? null : (string) $status;
    }

    private function participations(): int
    {
        return $this->scalar('SELECT COUNT(*) FROM event_participation');
    }

    private function audits(string $action): int
    {
        return $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    // ═══ Recherche d'une commune à inviter ═══════════════════════════════════

    public function testTheSearchFindsActiveMunicipalitiesByNameOrInseeAndSaysWhatTheyAre(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->municipality('Saumaise-la-Forêt', '33520');
        $this->municipality('Saumur-Inactive', '33521', MunicipalityStatus::Inactive);
        $this->municipality('Saumery-Suspendue', '33522', MunicipalityStatus::Suspended);
        $this->loginAs($this->w->adminO);

        $byName = $this->get($this->inviteUrl($this->w->org, $this->fire, '?q=Saum'));
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Saumos', $byName);
        self::assertStringContainsString('Saumaise-la-Forêt', $byName);
        self::assertStringContainsString('Code INSEE 33519', $byName);
        self::assertStringNotContainsString('Saumur-Inactive', $byName, 'commune inactive : introuvable');
        self::assertStringNotContainsString('Saumery-Suspendue', $byName, 'commune suspendue : indisponible');
        self::assertStringContainsString('value="'.$this->id($saumos).'"', $byName);

        $byInsee = $this->get($this->inviteUrl($this->w->org, $this->fire, '?q=3352'));
        self::assertStringContainsString('Saumaise-la-Forêt', $byInsee, 'code INSEE : préfixe');
        self::assertStringNotContainsString('Saumos', $byInsee);
    }

    public function testTheSearchFlagsMunicipalitiesThatCannotBeInvitedAndNeverShowsCommercialData(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->inviteUrl($this->w->org, $this->fire, '?q=Commune'));

        self::assertStringContainsString($this->w->guestB->name(), $html);
        self::assertStringContainsString('Participe déjà', $html);
        self::assertMatchesRegularExpression('/<input[^>]*value="'.$this->id($this->w->guestB).'"[^>]*disabled/', $html, 'non sélectionnable');
        self::assertStringNotContainsString('value="'.$this->id($this->w->org).'"', $html, 'l\'organisatrice n\'est pas proposée');
        // Aucune donnée commerciale par commune : seul l'avis général sur la participation sans abonnement est affiché.
        self::assertStringNotContainsString('Sans abonnement</span>', $html);

        $this->lifecycle()->close($this->user($this->w->adminO), $this->accident);
        $closed = $this->get($this->inviteUrl($this->w->org, $this->accident, '?q=Commune'));
        self::assertStringNotContainsString('value="'.$this->id($this->w->org).'"', $closed);
    }

    public function testTheSearchRulesShortInputNoResultAndWildcards(): void
    {
        $this->loginAs($this->w->adminO);

        self::assertStringContainsString('Recherchez une commune', $this->get($this->inviteUrl($this->w->org, $this->fire)));
        self::assertStringContainsString('Saisie trop courte', $this->get($this->inviteUrl($this->w->org, $this->fire, '?q=a')));
        self::assertStringContainsString('Commune non trouvée', $this->get($this->inviteUrl($this->w->org, $this->fire, '?q=introuvable')));
        foreach (['%', '_', '%%'] as $wildcard) {
            self::assertStringContainsString('Commune non trouvée', $this->get($this->inviteUrl($this->w->org, $this->fire, '?q='.rawurlencode($wildcard.'ab'))), $wildcard);
        }
        self::assertSame(200, $this->httpStatus());
        self::assertSame(200, $this->get($this->inviteUrl($this->w->org, $this->fire, '?q[]=x')) ? $this->httpStatus() : 0, 'valeur tableau ignorée');
    }

    // ═══ Invitation ══════════════════════════════════════════════════════════

    public function testInvitingAMunicipalityWritesTheInvitationItsZoneAndTheAudit(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->loginAs($this->w->adminO);
        $participations = $this->participations();
        $audits = $this->audits('PARTICIPATION_INVITED');

        $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($saumos), 'message' => 'Bienvenue dans la coordination.', 'q' => 'Saumos']);

        self::assertSame(303, $this->httpStatus());
        self::assertSame($this->communesUrl($this->w->org, $this->fire), parse_url($this->location(), \PHP_URL_PATH));
        self::assertSame($participations + 1, $this->participations());
        self::assertSame('INVITED', $this->participationStatus($saumos, $this->fire));
        self::assertSame($audits + 1, $this->audits('PARTICIPATION_INVITED'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM event_zone z JOIN event_participation p ON p.id = z.participation_id WHERE p.municipality_id = ?', [$saumos->id()->toBinary()]), 'zone « Commune entière » proposée');
        $page = $this->get($this->communesUrl($this->w->org, $this->fire));
        self::assertStringContainsString('L’invitation a été envoyée à Saumos', $page);
        self::assertStringContainsString('Saumos', $page);
        self::assertStringContainsString('Invitée', $page);
        self::assertStringContainsString('Bienvenue dans la coordination.', $page, 'message visible de l\'organisatrice (détails)');
    }

    public function testTheInvitationMessageHasNoArbitraryLengthLimit(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->loginAs($this->w->adminO);
        $message = str_repeat('Message très long. ', 1000); // ~19 000 caractères : la colonne est un LONGTEXT, aucune règle ne la borne

        $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($saumos), 'message' => $message]);

        self::assertSame(303, $this->httpStatus());
        self::assertSame(trim($message), $this->scalarString('SELECT invitation_message FROM event_participation WHERE municipality_id = ?', [$saumos->id()->toBinary()]));
    }

    public function testAnEventManagerMayInviteToo(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->loginAs($this->w->managerO);

        $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($saumos), 'message' => '']);

        self::assertSame(303, $this->httpStatus());
        self::assertSame('INVITED', $this->participationStatus($saumos, $this->fire));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'aucune commune' => [''];
        yield 'identifiant mal formé' => ['pas-un-uuid'];
        yield 'commune inconnue' => ['01a1a1d2-0000-7000-8000-000000000000'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSelections')]
    public function testAnInvalidOrForgedSelectionIsRefusedWithoutAnyWrite(string $municipalityId): void
    {
        $this->loginAs($this->w->adminO);
        $participations = $this->participations();

        $html = $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $municipalityId, 'message' => 'Message conservé', 'q' => 'x']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Corrigez', $html);
        self::assertStringContainsString('Message conservé', $html, 'saisies conservées');
        self::assertSame($participations, $this->participations());
    }

    public function testTheEngineRefusalsAreRestitutedAndNothingIsWritten(): void
    {
        $inactive = $this->municipality('Dormante', '33600', MunicipalityStatus::Inactive);
        $this->loginAs($this->w->adminO);
        $participations = $this->participations();

        $already = $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($this->w->guestB)]);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('déjà invitée', $already);

        $unavailable = $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($inactive)]);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('introuvable ou indisponible', $unavailable);

        $this->participationLifecycle()->close($this->user($this->w->adminB), (string) $this->scalarString('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_participation WHERE municipality_id = ?', [$this->w->guestB->id()->toBinary()]));
        $this->loginAs($this->w->adminO);
        $closed = $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($this->w->guestB)]);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('clôturée', $closed, 'DM-089 : jamais réinvitée');
        self::assertSame($participations, $this->participations());
    }

    public function testTheInvitationCsrfTokenIsRequired(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->loginAs($this->w->adminO);
        $participations = $this->participations();
        $url = $this->inviteUrl($this->w->org, $this->fire);

        $this->client->request('POST', $url, ['municipality_id' => $this->id($saumos)]);
        self::assertSame(422, $this->httpStatus());
        $html = $this->submit($url, ['municipality_id' => $this->id($saumos)], 'forgé');
        self::assertStringContainsString('Le formulaire a expiré', $html);
        $this->submit($url, ['municipality_id' => $this->id($saumos)], null, $this->inviteUrl($this->w->org, $this->accident));
        self::assertSame(422, $this->httpStatus(), 'jeton lié à l\'événement');
        self::assertSame($participations, $this->participations());
    }

    public function testInvitationPermissionsAndIsolation(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $participations = $this->participations();
        $cases = [
            'instructeur de l\'organisatrice' => [$this->w->instructorO, $this->w->org, $this->fire, 403],
            'commune participante' => [$this->w->adminB, $this->w->guestB, $this->fire, 403],
            'commune simplement invitée' => [$this->w->adminX, $this->w->other, $this->accident, 403],
            'commune étrangère, URL de l\'organisatrice' => [$this->w->adminX, $this->w->org, $this->fire, 404],
            'identifiant d\'événement forgé' => [$this->w->adminO, $this->w->org, self::randomUuid(), 404],
            'super-administrateur CWS' => [$this->w->superAdmin, $this->w->org, $this->fire, 404],
        ];
        foreach ($cases as $label => [$user, $municipality, $event, $status]) {
            $this->loginAs($user);
            $this->get($this->inviteUrl($municipality, $event));
            self::assertSame($status, $this->httpStatus(), $label.' (GET)');
            $this->client->request('POST', $this->inviteUrl($municipality, $event), ['municipality_id' => $this->id($saumos), '_token' => 'x']);
            self::assertSame($status, $this->httpStatus(), $label.' (POST)');
        }
        self::assertSame($participations, $this->participations());
    }

    public function testInvitingIsRefusedOnAClosedEventOrFromASuspendedMunicipality(): void
    {
        $this->loginAs($this->w->adminO);
        $this->get($this->inviteUrl($this->w->org, $this->flood));
        self::assertSame(403, $this->httpStatus(), 'événement clôturé');

        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->org->id()->toBinary()]);
        $this->loginAs($this->w->adminO);
        $this->get($this->inviteUrl($this->w->org, $this->fire));
        self::assertSame(403, $this->httpStatus(), 'commune suspendue');
    }

    public function testWriteRoutesRequireAFullAuthentication(): void
    {
        foreach ([$this->inviteUrl($this->w->org, $this->fire), $this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), $this->actionUrl($this->w->other, $this->accident, 'accepter')] as $url) {
            $this->client->request('GET', $url);
            self::assertTrue($this->client->getResponse()->isRedirect('http://localhost/login') || 405 === $this->httpStatus(), $url);
            $this->client->request('POST', $url, ['_token' => 'x']);
            self::assertResponseRedirects('http://localhost/login', 302, $url);
        }
    }

    // ═══ Acceptation, commune non abonnée ════════════════════════════════════

    public function testANonSubscribedMunicipalityAcceptsFreeOfChargeAndCreatesNothingCommercial(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $admin = $this->userIn($saumos, MunicipalityRole::MunicipalityAdmin);
        $this->loginAs($this->w->adminO);
        $this->submit($this->inviteUrl($this->w->org, $this->fire), ['municipality_id' => $this->id($saumos), 'message' => 'Bienvenue']);
        self::assertSame('INVITED', $this->participationStatus($saumos, $this->fire));
        $before = [$this->scalar('SELECT COUNT(*) FROM municipality_commercial_access'), $this->scalar('SELECT COUNT(*) FROM identity_user'), $this->scalar('SELECT COUNT(*) FROM municipality_membership'), $this->scalar('SELECT COUNT(*) FROM municipality')];

        $this->loginAs($admin);
        $invitation = $this->get($this->eventUrl($saumos, $this->fire));
        self::assertStringContainsString('Invitation de', $invitation);
        self::assertStringContainsString('Bienvenue', $invitation);
        self::assertStringContainsString('n’a pas d’abonnement', $invitation, 'information : participation gratuite');
        self::assertStringContainsString('/participation/accepter"', $invitation);

        $this->submit($this->actionUrl($saumos, $this->fire, 'accepter'), [], null, $this->eventUrl($saumos, $this->fire));

        self::assertSame(303, $this->httpStatus());
        self::assertSame($this->eventUrl($saumos, $this->fire), parse_url($this->location(), \PHP_URL_PATH));
        self::assertSame('ACTIVE', $this->participationStatus($saumos, $this->fire));
        self::assertSame(1, $this->audits('PARTICIPATION_ACCEPTED') - 1, 'un audit d\'acceptation de plus (celui de B en préparation)');
        self::assertSame($before, [$this->scalar('SELECT COUNT(*) FROM municipality_commercial_access'), $this->scalar('SELECT COUNT(*) FROM identity_user'), $this->scalar('SELECT COUNT(*) FROM municipality_membership'), $this->scalar('SELECT COUNT(*) FROM municipality')], 'ni abonnement, ni droit, ni utilisateur, ni adhésion');
        $page = $this->get($this->eventUrl($saumos, $this->fire));
        self::assertStringContainsString('L’invitation a été acceptée', $page);
        self::assertStringContainsString('Participante', $page);
        // Participer ne donne pas le droit de créer ses propres événements.
        $list = $this->get($this->listUrl($saumos));
        self::assertStringNotContainsString('/evenements/nouveau"', $list);
        $this->get($this->listUrl($saumos).'/nouveau');
        self::assertSame(403, $this->httpStatus());
        self::assertStringContainsString('Participation gratuite', $this->get($this->communesUrl($saumos, $this->fire)));
    }

    public function testOnlyAnAdministratorOfTheInvitedMunicipalityMayAcceptAndOnlyOnce(): void
    {
        $manager = $this->userIn($this->w->other, MunicipalityRole::EventManager);
        $participationOfOther = (string) $this->scalarString('SELECT status FROM event_participation WHERE municipality_id = ?', [$this->w->other->id()->toBinary()]);
        self::assertSame('INVITED', $participationOfOther);

        $this->loginAs($manager);
        self::assertStringNotContainsString('/participation/accepter"', $this->get($this->eventUrl($this->w->other, $this->accident)), 'le gestionnaire ne peut pas accepter (R2)');
        $this->client->request('POST', $this->actionUrl($this->w->other, $this->accident, 'accepter'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('INVITED', $this->participationStatus($this->w->other, $this->accident));

        $this->loginAs($this->w->adminX);
        $this->submit($this->actionUrl($this->w->other, $this->accident, 'accepter'), [], null, $this->eventUrl($this->w->other, $this->accident));
        self::assertSame(303, $this->httpStatus());
        self::assertSame('ACTIVE', $this->participationStatus($this->w->other, $this->accident));

        $this->client->request('POST', $this->actionUrl($this->w->other, $this->accident, 'accepter'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus(), 'déjà acceptée : action indisponible');
    }

    public function testAcceptingNeverActsOnAParticipationChosenByTheClient(): void
    {
        $participationB = (string) $this->scalarString('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_participation WHERE municipality_id = ?', [$this->w->guestB->id()->toBinary()]);
        $this->loginAs($this->w->adminX);

        $this->submit($this->actionUrl($this->w->other, $this->accident, 'accepter'), ['participation_id' => $participationB, 'municipality_id' => $this->id($this->w->guestB)], null, $this->eventUrl($this->w->other, $this->accident));

        self::assertSame(303, $this->httpStatus());
        self::assertSame('ACTIVE', $this->participationStatus($this->w->other, $this->accident), 'sa propre participation');
        self::assertSame('ACTIVE', $this->participationStatus($this->w->guestB, $this->fire), 'celle de B n\'est pas touchée');
        self::assertNull($this->participationStatus($this->w->guestB, $this->accident));
    }

    public function testAcceptingIsRefusedForAnotherEventAnotherMunicipalityOrAClosedEvent(): void
    {
        $cases = [
            'commune sans participation à cet événement' => [$this->w->adminB, $this->w->guestB, $this->accident, 404],
            'URL de l\'organisatrice' => [$this->w->adminX, $this->w->org, $this->accident, 404],
            'événement forgé' => [$this->w->adminX, $this->w->other, self::randomUuid(), 404],
            'organisatrice (aucune invitation)' => [$this->w->adminO, $this->w->org, $this->accident, 403],
            'super-administrateur' => [$this->w->superAdmin, $this->w->other, $this->accident, 404],
        ];
        foreach ($cases as $label => [$user, $municipality, $event, $status]) {
            $this->loginAs($user);
            $this->client->request('POST', $this->actionUrl($municipality, $event, 'accepter'), ['_token' => 'x']);
            self::assertSame($status, $this->httpStatus(), $label);
        }
        self::assertSame('INVITED', $this->participationStatus($this->w->other, $this->accident));

        $this->lifecycle()->close($this->user($this->w->adminO), $this->accident);
        $this->loginAs($this->w->adminX);
        $this->client->request('POST', $this->actionUrl($this->w->other, $this->accident, 'accepter'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus(), 'après la clôture : jamais acceptée');
        self::assertNotSame('ACTIVE', $this->participationStatus($this->w->other, $this->accident));
    }

    public function testTheAcceptCsrfTokenIsRequiredAndTheInvitationStaysVisible(): void
    {
        $this->loginAs($this->w->adminX);

        $this->client->request('POST', $this->actionUrl($this->w->other, $this->accident, 'accepter'), ['_token' => 'forgé']);
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertStringContainsString('Invitation de', $html, 'page réaffichée complète');
        self::assertSame('INVITED', $this->participationStatus($this->w->other, $this->accident));
    }

    // ═══ Sa participation : suspendre, réactiver, clôturer ═══════════════════

    private function participationLifecycle(): EventParticipationService
    {
        return static::getContainer()->get(EventParticipationService::class);
    }

    private function lifecycle(): EventLifecycleService
    {
        return static::getContainer()->get(EventLifecycleService::class);
    }

    /**
     * @param list<mixed> $params
     */
    private function scalarString(string $sql, array $params = []): string
    {
        return (string) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    public function testAParticipatingMunicipalitySuspendsReactivatesAndClosesItsOwnParticipation(): void
    {
        $this->loginAs($this->w->adminB);
        $page = $this->communesUrl($this->w->guestB, $this->fire);
        $closedBefore = $this->audits('PARTICIPATION_CLOSED');

        $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), ['reason' => 'Moyens indisponibles'], $this->actionToken($page, 'suspendre'));
        self::assertSame(303, $this->httpStatus());
        self::assertSame($page, parse_url($this->location(), \PHP_URL_PATH));
        self::assertSame('SUSPENDED', $this->participationStatus($this->w->guestB, $this->fire));
        self::assertSame('Moyens indisponibles', $this->scalarString('SELECT suspension_reason FROM event_participation WHERE municipality_id = ?', [$this->w->guestB->id()->toBinary()]));
        self::assertSame(1, $this->audits('PARTICIPATION_SUSPENDED'));
        $suspended = $this->get($page);
        self::assertStringContainsString('Votre participation a été suspendue', $suspended);
        self::assertStringContainsString('Réactiver ma participation', $suspended);
        self::assertStringNotContainsString('Suspendre ma participation', $suspended, 'sans objet une fois suspendue');

        $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), [], $this->actionToken($page, 'reactiver'));
        self::assertSame(303, $this->httpStatus());
        self::assertSame('ACTIVE', $this->participationStatus($this->w->guestB, $this->fire));
        self::assertSame(1, $this->audits('PARTICIPATION_REACTIVATED'));

        $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'cloturer'), [], $this->actionToken($page, 'cloturer'));
        self::assertSame(303, $this->httpStatus());
        self::assertSame('CLOSED', $this->participationStatus($this->w->guestB, $this->fire));
        self::assertSame($closedBefore + 1, $this->audits('PARTICIPATION_CLOSED'));
        $closed = $this->get($page);
        self::assertStringContainsString('Votre participation a été clôturée', $closed);
        self::assertStringContainsString('Votre participation est clôturée : c’est définitif', $closed);
        self::assertStringNotContainsString('Réactiver ma participation', $closed);
        $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus(), 'une participation clôturée ne se réactive jamais');
        // Les autres communes ne sont pas affectées.
        self::assertSame('ACTIVE', $this->scalarString('SELECT status FROM event_participation WHERE municipality_id = ?', [$this->w->org->id()->toBinary()]));
    }

    public function testSuspendingNeedsAReasonAndKeepsTheScreenOpen(): void
    {
        $this->loginAs($this->w->adminB);
        $page = $this->communesUrl($this->w->guestB, $this->fire);

        $html = $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), ['reason' => '   '], $this->actionToken($page, 'suspendre'));

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Indiquez la raison de la suspension', $html);
        self::assertStringNotContainsString('data-panel="psuspend" hidden', $html, 'le panneau reste ouvert');
        self::assertSame('ACTIVE', $this->participationStatus($this->w->guestB, $this->fire));

        $html = $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), ['reason' => 'Raison à conserver'], 'forgé');
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Le formulaire a expiré', $html);
        self::assertStringContainsString('Raison à conserver', $html, 'saisie conservée');
        self::assertSame('ACTIVE', $this->participationStatus($this->w->guestB, $this->fire));
    }

    public function testParticipationActionsAreLimitedToTheOwnParticipationAndToTheRightRoles(): void
    {
        $manager = $this->userIn($this->w->guestB, MunicipalityRole::EventManager);
        $instructor = $this->userIn($this->w->guestB, MunicipalityRole::Instructor);

        // Gestionnaire : peut suspendre sa participation (DM-057), pas l'accepter.
        $this->loginAs($manager);
        $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), ['reason' => 'Gestionnaire'], $this->actionToken($this->communesUrl($this->w->guestB, $this->fire), 'suspendre'));
        self::assertSame(303, $this->httpStatus());
        self::assertSame('SUSPENDED', $this->participationStatus($this->w->guestB, $this->fire));

        // Instructeur : aucune action.
        $this->loginAs($instructor);
        $page = $this->get($this->communesUrl($this->w->guestB, $this->fire));
        self::assertStringNotContainsString('Réactiver ma participation', $page);
        $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus());

        // L'organisatrice n'agit pas sur la participation d'une autre commune ni sur la sienne seule.
        $this->loginAs($this->w->adminO);
        $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), ['_token' => 'x']);
        self::assertSame(404, $this->httpStatus(), 'URL d\'une commune dont elle n\'est pas membre');
        $this->client->request('POST', $this->actionUrl($this->w->org, $this->fire, 'cloturer'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus(), 'la participation de l\'organisatrice se clôture avec l\'événement');
        $this->client->request('POST', $this->actionUrl($this->w->org, $this->fire, 'suspendre'), ['reason' => 'x', '_token' => 'x']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('SUSPENDED', $this->participationStatus($this->w->guestB, $this->fire));
        self::assertSame('ACTIVE', $this->participationStatus($this->w->org, $this->fire));

        // Commune étrangère et super-administrateur : 404 ; commune suspendue : refusé.
        foreach ([$this->w->adminX, $this->w->superAdmin] as $outsider) {
            $this->loginAs($outsider);
            $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), ['_token' => 'x']);
            self::assertSame(404, $this->httpStatus());
        }
        $this->em()->getConnection()->executeStatement("UPDATE municipality SET status = 'SUSPENDED' WHERE id = ?", [$this->w->guestB->id()->toBinary()]);
        $this->loginAs($this->w->adminB);
        $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, 'reactiver'), ['_token' => 'x']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('SUSPENDED', $this->participationStatus($this->w->guestB, $this->fire));
    }

    public function testNoParticipationActionAfterTheEventIsClosed(): void
    {
        $this->lifecycle()->close($this->user($this->w->adminO), $this->fire);
        $this->loginAs($this->w->adminB);

        foreach (['suspendre', 'reactiver', 'cloturer'] as $action) {
            $this->client->request('POST', $this->actionUrl($this->w->guestB, $this->fire, $action), ['reason' => 'x', '_token' => 'x']);
            self::assertSame(403, $this->httpStatus(), $action);
        }
        self::assertSame('CLOSED', $this->participationStatus($this->w->guestB, $this->fire), 'clôturée avec l\'événement (DM-092)');
    }

    // ═══ Communes visibles selon la commune (DM-084, DM-090, DM-091) ═════════

    public function testEachLevelSeesOnlyWhatItsVisibilityAllows(): void
    {
        // `other` est invitée en attente à « Incendie » (invitation de l'organisatrice, jamais acceptée).
        $this->participationLifecycle()->invite($this->user($this->w->adminO), new \App\Event\Application\Command\InviteMunicipalityCommand($this->fire, $this->w->other->id()->toRfc4122(), 'Message interne pour la commune'));
        $this->participationLifecycle()->suspend($this->user($this->w->adminB), (string) $this->scalarString('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_participation WHERE municipality_id = ?', [$this->w->guestB->id()->toBinary()]), 'Raison administrative interne');
        $pending = $this->w->other->name();

        foreach ([$this->w->adminO, $this->w->managerO] as $manager) {
            $this->loginAs($manager);
            $full = $this->get($this->communesUrl($this->w->org, $this->fire));
            self::assertStringContainsString($pending, $full, 'visibilité complète : invitations en attente');
            self::assertStringContainsString('Message interne pour la commune', $full);
            self::assertStringContainsString('Raison administrative interne', $full, 'U5 : motifs pour l\'administrateur et le gestionnaire');
            self::assertStringContainsString('Détails', $full);
            self::assertStringNotContainsString('abonnement', strtolower($full), 'aucune donnée commerciale entre communes');
        }

        foreach ([[$this->w->instructorO, $this->w->org], [$this->w->adminB, $this->w->guestB]] as [$viewer, $municipality]) {
            $this->loginAs($viewer);
            $coordination = $this->get($this->communesUrl($municipality, $this->fire));
            self::assertSame(200, $this->httpStatus());
            self::assertStringContainsString($this->w->guestB->name(), $coordination);
            self::assertStringContainsString($this->w->org->name(), $coordination);
            foreach ([$pending, 'Message interne', 'Raison administrative interne'] as $hidden) {
                self::assertStringNotContainsString($hidden, $coordination, 'DM-090 : '.$hidden);
            }
            self::assertStringNotContainsString('Détails', $coordination);
        }

        $this->loginAs($this->w->adminX);
        $invited = $this->get($this->communesUrl($this->w->other, $this->fire));
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Liste non disponible', $invited);
        self::assertStringNotContainsString($this->w->guestB->name(), $invited, 'une commune invitée ne voit jamais la liste');
    }

    public function testTheHistoryOfAClosedEventKeepsOnlyTheMunicipalitiesThatTookPart(): void
    {
        $this->participationLifecycle()->invite($this->user($this->w->adminO), new \App\Event\Application\Command\InviteMunicipalityCommand($this->fire, $this->w->other->id()->toRfc4122()));
        $this->lifecycle()->close($this->user($this->w->adminO), $this->fire);

        $this->loginAs($this->w->adminB);
        $history = $this->get($this->communesUrl($this->w->guestB, $this->fire));
        self::assertStringContainsString('A participé', $history);
        self::assertStringContainsString($this->w->org->name(), $history);
        self::assertStringNotContainsString($this->w->other->name(), $history, 'invitation jamais acceptée : invisible (U6)');

        $this->loginAs($this->w->adminX);
        self::assertStringContainsString('Liste non disponible', $this->get($this->communesUrl($this->w->other, $this->fire)));
    }

    public function testTheCommunesPageIsInaccessibleToOthersAndForgedIdentifiers(): void
    {
        $cases = [
            [$this->w->adminX, $this->w->org, $this->fire],
            [$this->w->adminB, $this->w->guestB, $this->flood],
            [$this->w->adminO, $this->w->org, self::randomUuid()],
            [$this->w->superAdmin, $this->w->org, $this->fire],
        ];
        $bodies = [];
        foreach ($cases as [$user, $municipality, $event]) {
            $this->loginAs($user);
            $bodies[] = $this->get($this->communesUrl($municipality, $event));
            self::assertSame(404, $this->httpStatus());
        }
        self::assertSame(1, \count(array_unique($bodies)));
    }

    public function testTheRecordLinksToTheCoordinationScreens(): void
    {
        $this->loginAs($this->w->adminO);

        $html = $this->get($this->eventUrl($this->w->org, $this->fire));

        self::assertStringContainsString('href="'.$this->inviteUrl($this->w->org, $this->fire).'"', $html, 'Inviter est branchée');
        self::assertStringContainsString('href="'.$this->communesUrl($this->w->org, $this->fire).'"', $html, 'rubrique Communes');
        $communes = $this->get($this->communesUrl($this->w->org, $this->fire));
        self::assertStringContainsString('Inviter une commune', $communes);
        self::assertStringContainsString('aria-current="page"', $communes);
    }

    // ═══ Conflits transactionnels ════════════════════════════════════════════

    public function testAConflictWhileInvitingIsReportedAndTheInputIsKept(): void
    {
        $saumos = $this->municipality('Saumos', '33519');
        $this->loginAs($this->w->adminO);
        $url = $this->inviteUrl($this->w->org, $this->fire);
        $token = $this->token($url);
        $participations = $this->participations();
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM event_event WHERE name = ? FOR UPDATE', ['Incendie du massif forestier']);

        try {
            $html = $this->submit($url, ['municipality_id' => $this->id($saumos), 'message' => 'Message à ne pas perdre', 'q' => 'Saumos'], $token);
        } finally {
            $other->rollBack();
        }

        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('Un autre traitement est en cours', $html);
        self::assertStringContainsString('Message à ne pas perdre', $html);
        self::assertStringContainsString('name="_token"', $html, 'page complète réaffichée');
        self::assertSame($participations, $this->participations(), 'aucun succès annoncé');

        $this->submit($url, ['municipality_id' => $this->id($saumos), 'message' => 'Message à ne pas perdre']);
        self::assertSame(303, $this->httpStatus(), 'nouvelle tentative manuelle');
    }

    public function testAConflictOnAParticipationActionIsReportedAndTheReasonIsKept(): void
    {
        $this->loginAs($this->w->adminB);
        $page = $this->communesUrl($this->w->guestB, $this->fire);
        $token = $this->token($page);
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM event_participation WHERE municipality_id = ? FOR UPDATE', [$this->w->guestB->id()->toBinary()]);

        try {
            $html = $this->submit($this->actionUrl($this->w->guestB, $this->fire, 'suspendre'), ['reason' => 'Raison à ne pas perdre'], $token);
        } finally {
            $other->rollBack();
        }

        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('Un autre traitement est en cours', $html);
        self::assertStringContainsString('Raison à ne pas perdre', $html);
        self::assertSame('ACTIVE', $this->participationStatus($this->w->guestB, $this->fire), 'rien d\'enregistré');
    }
}
