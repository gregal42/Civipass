<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Identity\Domain\User;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Actions nommées du back-office CWS (LOT 07.1) : parcours complet d'ouverture d'une commune VIERGE jusqu'à l'acceptation de
 * l'invitation de son administrateur, sans fixture contournant les maillons, puis accès, CSRF, identifiants forgés et séparation.
 */
final class CwsAdministrationTest extends WebTestCase
{
    private KernelBrowser $client;
    private RecordingAdminInvitationNotifier $mails;
    private User $root;

    protected function setUp(): void
    {
        $this->client = $this->freshClient();
        $this->client->disableReboot();
        $this->mails = new RecordingAdminInvitationNotifier();
        static::getContainer()->set(AdminInvitationNotifier::class, $this->mails);
        $this->root = $this->createAccount('root@example.test', superAdmin: true);
        $this->em()->persist(new NationalCommune(new NationalCommuneRecord('33503', 'Saumos', '33', '75', ['33680'], 1800), 'test', EventFixtures::now()));
        $this->em()->flush();
    }

    private function login(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->submitLogin($this->client, $email, self::PASSWORD);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** Jeton CSRF lu dans le formulaire de la page courante. */
    private function tokenIn(string $selector): string
    {
        return (string) $this->client->getCrawler()->filter($selector.' input[name=_token]')->first()->attr('value');
    }

    // ───────────────────────── Parcours complet ─────────────────────────

    public function testPilotCommuneOpeningFromBlankUntilTheAdministratorAcceptsHisInvitation(): void
    {
        $this->login('root@example.test');

        // 1. Ouvrir la commune depuis le référentiel.
        $this->client->request('GET', '/admin/cws/communes/ouvrir?q=saumos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Saumos');
        $this->client->request('POST', '/admin/cws/communes/ouvrir', ['_token' => $this->tokenIn('form[action$="/ouvrir"]'), 'insee' => '33503']);
        self::assertResponseRedirects();
        $fiche = (string) $this->client->getResponse()->headers->get('Location');
        $id = basename($fiche);
        $this->client->request('GET', $fiche);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'En attente d’administrateur');
        self::assertSelectorTextContains('body', 'Droit de créer de nouveaux événements');

        // 2. Accès commercial.
        $this->client->request('POST', "/admin/cws/communes/$id/acces", ['_token' => $this->tokenIn('form[action$="/acces"]'), 'kind' => 'grant', 'reason' => 'Pilote 2026', 'expires_on' => '']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Accès commercial attribué');

        // 3. Invitation de l'administrateur : l'e-mail part, aucun droit n'est donné.
        $this->client->request('POST', "/admin/cws/communes/$id/administrateurs/inviter", ['_token' => $this->tokenIn('form[action$="/inviter"]'), 'email' => 'maire@saumos.example']);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invitation envoyée');
        self::assertSelectorTextContains('body', 'En attente d’administrateur');
        $token = $this->mails->lastToken();
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent(), 'le lien n’est jamais affiché à CWS');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));

        // 4. Le futur administrateur (visiteur anonyme) ouvre le lien, crée son compte et atteste.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/invitation/$token");
        self::assertResponseRedirects('/invitation');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Administrer Saumos');
        $this->client->request('POST', '/invitation/rejoindre', [
            '_token' => $this->tokenIn('form[action$="/invitation/rejoindre"]'),
            'first_name' => 'Claire', 'last_name' => 'Martin', 'password' => 'une phrase de passe longue', 'password_confirm' => 'une phrase de passe longue', 'attest' => '1',
        ]);
        self::assertResponseRedirects();
        $events = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/communes/[0-9a-f-]{36}/evenements$~', $events);

        // 5. Il est administrateur, connecté, et peut préparer un événement (accès commercial valide).
        $this->client->request('GET', $events);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $events.'/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSame(['MUNICIPALITY_ADMIN'], (array) json_decode((string) $this->db()->fetchOne('SELECT roles FROM municipality_membership'), true));

        // 6. Le lien est épuisé.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/invitation/$token");
        self::assertResponseStatusCodeSame(410);

        // 7. CWS voit l'invitation acceptée et l'historique administratif, sans aucune donnée opérationnelle.
        $this->login('root@example.test');
        $this->client->request('GET', $fiche);
        self::assertSelectorTextContains('body', 'Acceptée');
        self::assertSelectorTextContains('body', 'Invitation d’administrateur acceptée');
        self::assertSelectorTextContains('body', 'Commune ouverte');
    }

    // ───────────────────────── Accès et CSRF ─────────────────────────

    /** @return list<array{string, string}> */
    private function urls(string $mid, string $aid): array
    {
        return [
            ['GET', '/admin/cws/communes/ouvrir'], ['POST', '/admin/cws/communes/ouvrir'], ['GET', "/admin/cws/communes/$mid"],
            ['POST', "/admin/cws/communes/$mid/acces"], ['POST', "/admin/cws/communes/$mid/administrateurs/inviter"], ['POST', "/admin/cws/communes/$mid/contact"],
            ['GET', "/admin/cws/confirmer/commune_suspend/$mid"], ['POST', "/admin/cws/executer/commune_suspend/$mid"], ['GET', "/admin/cws/comptes/$aid"],
        ];
    }

    public function testOnlyAFullyAuthenticatedSuperAdminReachesTheNamedActions(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $agent = $this->createAccount('agent@example.test');
        $this->em()->persist($commune);
        $this->em()->persist(MembershipBuilder::aMembership($agent, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->em()->flush();
        $mid = $commune->id()->toRfc4122();

        foreach ($this->urls($mid, $agent->id()->toRfc4122()) as [$method, $url]) {
            $this->client->getCookieJar()->clear();
            $this->client->request($method, $url);
            self::assertResponseRedirects('/login', null, "anonyme $method $url");
        }
        $this->login('agent@example.test'); // administrateur communal : jamais autorisé
        foreach ($this->urls($mid, $agent->id()->toRfc4122()) as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, "administrateur communal $method $url");
        }
        $before = $this->db()->fetchOne('SELECT status FROM municipality WHERE id = ?', [$commune->id()->toBinary()]);
        self::assertSame('ACTIVE', $before);
    }

    public function testASessionRestoredOnlyByRememberMeCannotWrite(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['email' => 'root@example.test', 'password' => self::PASSWORD, '_remember_me' => '1']);
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/admin/cws/communes/ouvrir');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/admin/cws/communes/ouvrir', ['insee' => '33503']);
        self::assertResponseRedirects('/login');
    }

    public function testEveryActionRequiresItsOwnCsrfToken(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->em()->persist($commune);
        $this->em()->flush();
        $mid = $commune->id()->toRfc4122();
        $this->login('root@example.test');
        $this->client->request('GET', "/admin/cws/confirmer/commune_deactivate/$mid");
        $otherActionToken = $this->tokenIn('form[action*="/executer/"]');

        foreach ([
            ['/admin/cws/communes/ouvrir', ['insee' => '33503']],
            ["/admin/cws/communes/$mid/acces", ['kind' => 'grant', 'reason' => 'x']],
            ["/admin/cws/communes/$mid/administrateurs/inviter", ['email' => 'a@b.example']],
            ["/admin/cws/communes/$mid/contact", ['contact_name' => 'x']],
            ["/admin/cws/executer/commune_suspend/$mid", []],
            ["/admin/cws/executer/commune_suspend/$mid", ['_token' => 'forgé']],
            ["/admin/cws/executer/commune_suspend/$mid", ['_token' => $otherActionToken]], // jeton d'une AUTRE action
        ] as [$url, $fields]) {
            $this->client->request('POST', $url, $fields);
            self::assertResponseRedirects(null, 303, $url);
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', 'expiré');
        }
        self::assertSame('ACTIVE', $this->db()->fetchOne('SELECT status FROM municipality WHERE id = ?', [$commune->id()->toBinary()]));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality'), 'aucune commune ouverte');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_commercial_access'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation'));
    }

    public function testGetPagesNeverWrite(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->em()->persist($commune);
        $this->em()->flush();
        $mid = $commune->id()->toRfc4122();
        $this->login('root@example.test');
        $audit = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log');
        foreach (['commune_suspend', 'commune_deactivate', 'commune_reactivate'] as $action) {
            $this->client->request('GET', "/admin/cws/confirmer/$action/$mid");
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Ce qui se passera');
        }
        $this->client->request('GET', "/admin/cws/communes/$mid");
        self::assertResponseIsSuccessful();
        self::assertSame($audit, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log'));
        self::assertSame('ACTIVE', $this->db()->fetchOne('SELECT status FROM municipality WHERE id = ?', [$commune->id()->toBinary()]));
    }

    // ───────────────────────── Identifiants forgés et séparation ─────────────────────────

    public function testForgedAndMismatchedIdentifiersAreUniform404s(): void
    {
        $a = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $admin = $this->createAccount('admin@example.test');
        $agent = $this->createAccount('agent@example.test');
        $mAdmin = MembershipBuilder::aMembership($admin, $a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $mAgent = MembershipBuilder::aMembership($agent, $a)->withRoles(MunicipalityRole::Instructor)->build();
        foreach ([$a, $mAdmin, $mAgent] as $e) {
            $this->em()->persist($e);
        }
        $this->em()->flush();
        $this->login('root@example.test');
        $unknown = '0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000';
        $mid = $a->id()->toRfc4122();

        foreach ([
            ['GET', "/admin/cws/communes/$unknown"],
            ['GET', "/admin/cws/confirmer/inconnue/$mid"],
            ['GET', "/admin/cws/confirmer/admin_remove/$unknown"],
            ['GET', "/admin/cws/confirmer/admin_remove/{$mAgent->id()->toRfc4122()}"],   // CWS ne touche pas aux agents (A3)
            ['GET', "/admin/cws/confirmer/access_revoke/$mid"],                           // identifiant d'un autre type
            ['GET', "/admin/cws/confirmer/invitation_revoke/{$mAdmin->id()->toRfc4122()}"],
            ['GET', "/admin/cws/confirmer/user_deactivate/{$this->root->id()->toRfc4122()}"], // jamais un compte CWS
            ['GET', "/admin/cws/comptes/{$this->root->id()->toRfc4122()}"],
            ['POST', "/admin/cws/executer/user_reset/{$this->root->id()->toRfc4122()}"],
            ['GET', '/admin/cws/communes/pas-un-uuid'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(404, "$method $url");
        }
        self::assertTrue($mAgent->isActive());
        self::assertTrue($mAdmin->isActive());
    }

    public function testCwsCannotInviteItselfOrAttachAnAccountThroughTheBackOffice(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->em()->persist($commune);
        $this->em()->flush();
        $mid = $commune->id()->toRfc4122();
        $this->login('root@example.test');
        $this->client->request('GET', "/admin/cws/communes/$mid");
        $this->client->request('POST', "/admin/cws/communes/$mid/administrateurs/inviter", ['_token' => $this->tokenIn('form[action$="/inviter"]'), 'email' => 'root@example.test']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'compte CWS');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation'));
        self::assertSame([], $this->mails->sent);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testTheLastAdministratorCannotBeRemovedOrDeactivatedFromTheBackOffice(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $admin = $this->createAccount('admin@example.test');
        $membership = MembershipBuilder::aMembership($admin, $commune)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->em()->persist($commune);
        $this->em()->persist($membership);
        $this->em()->flush();
        $this->login('root@example.test');

        foreach ([['admin_remove', $membership->id()->toRfc4122()], ['user_deactivate', $admin->id()->toRfc4122()]] as [$action, $target]) {
            $this->client->request('GET', "/admin/cws/confirmer/$action/$target");
            self::assertResponseIsSuccessful();
            $this->client->request('POST', "/admin/cws/executer/$action/$target", ['_token' => $this->tokenIn('form[action*="/executer/"]')]);
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', 'dernier administrateur');
        }
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_membership WHERE active = 1'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT active FROM identity_user WHERE email = ?', ['admin@example.test']));
        // Un lien de réinitialisation, lui, est possible (compte actif) et n'expose rien à CWS.
        $this->client->request('GET', "/admin/cws/confirmer/user_reset/{$admin->id()->toRfc4122()}");
        $this->client->request('POST', "/admin/cws/executer/user_reset/{$admin->id()->toRfc4122()}", ['_token' => $this->tokenIn('form[action*="/executer/"]')]);
        self::assertCount(1, self::getMailerMessages(), 'le lien part par e-mail, à l’adresse du compte seulement');
        self::assertSame('admin@example.test', self::emailAt(0)->getTo()[0]->getAddress());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'fait');
        self::assertStringNotContainsString('reset-password', (string) $this->client->getResponse()->getContent(), 'CWS ne voit jamais le lien');
    }

    public function testSuspendingARevokedAccessAndTheCommuneKeepTheirEffectsSeparate(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->em()->persist($commune);
        $this->em()->flush();
        $mid = $commune->id()->toRfc4122();
        $this->login('root@example.test');
        $this->client->request('GET', "/admin/cws/communes/$mid");
        $this->client->request('POST', "/admin/cws/communes/$mid/acces", ['_token' => $this->tokenIn('form[action$="/acces"]'), 'kind' => 'grant', 'reason' => 'Pilote', 'expires_on' => '']);
        $accessId = bin2hex((string) $this->db()->fetchOne('SELECT id FROM municipality_commercial_access'));
        $accessUuid = \Symfony\Component\Uid\Uuid::fromString(substr($accessId, 0, 8).'-'.substr($accessId, 8, 4).'-'.substr($accessId, 12, 4).'-'.substr($accessId, 16, 4).'-'.substr($accessId, 20))->toRfc4122();

        $this->client->request('GET', "/admin/cws/confirmer/access_revoke/$accessUuid");
        self::assertSelectorTextContains('body', 'événements déjà ouverts');
        $this->client->request('POST', "/admin/cws/executer/access_revoke/$accessUuid", ['_token' => $this->tokenIn('form[action*="/executer/"]')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Révoqué');
        self::assertSame('ACTIVE', $this->db()->fetchOne('SELECT status FROM municipality WHERE id = ?', [$commune->id()->toBinary()]), 'révoquer l’accès ne suspend pas la commune');

        $this->client->request('GET', "/admin/cws/confirmer/commune_suspend/$mid");
        $this->client->request('POST', "/admin/cws/executer/commune_suspend/$mid", ['_token' => $this->tokenIn('form[action*="/executer/"]')]);
        self::assertSame('SUSPENDED', $this->db()->fetchOne('SELECT status FROM municipality WHERE id = ?', [$commune->id()->toBinary()]));
        self::assertContains('MUNICIPALITY_STATUS_CHANGED', $this->db()->fetchFirstColumn('SELECT action FROM audit_log'));
    }

    public function testNoOperationalDataIsReachableOrShownByTheNamedActions(): void
    {
        $commune = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->em()->persist($commune);
        $this->em()->flush();
        $this->login('root@example.test');
        // Aucun droit communal implicite : les pages métier d'une commune restent fermées au super-administrateur.
        foreach (['evenements', 'demandes', 'archives', 'droits'] as $page) {
            $this->client->request('GET', '/communes/'.$commune->id()->toRfc4122().'/'.$page);
            self::assertResponseStatusCodeSame(404, $page);
        }
        $this->client->request('GET', '/admin/cws/communes/'.$commune->id()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['/evenements', '/demandes', '/laissez-passer', '/archives'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }
}
