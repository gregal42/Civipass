<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\RecordingAdminInvitationNotifier;
use App\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Écran « Équipe » (LOT 07.2) : parcours complet de l'ouverture par CWS au retrait d'un instructeur, coupure immédiate des accès,
 * accès par rôle, CSRF, identifiants forgés entre communes, commune suspendue, dernier administrateur, multi-communes, confidentialité.
 */
final class TeamPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private RecordingAdminInvitationNotifier $mails;
    private Municipality $a;
    private Municipality $b;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        $this->client = $this->freshClient();
        $this->client->disableReboot();
        $this->mails = new RecordingAdminInvitationNotifier();
        static::getContainer()->set(AdminInvitationNotifier::class, $this->mails);
    }

    private function world(): void
    {
        $this->a = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->b = MunicipalityBuilder::aMunicipality()->withInseeCode('69123')->build();
        $this->adminA = $this->createAccount('admin-a@mairie.example');
        $this->adminB = $this->createAccount('admin-b@mairie.example');
        foreach ([$this->a, $this->b, MembershipBuilder::aMembership($this->adminA, $this->a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(), MembershipBuilder::aMembership($this->adminB, $this->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build()] as $e) {
            $this->em()->persist($e);
        }
        $this->em()->flush();
    }

    private function member(string $email, Municipality $commune, MunicipalityRole ...$roles): MunicipalityMembership
    {
        $user = $this->createAccount($email);
        $m = MembershipBuilder::aMembership($user, $commune)->withRoles(...$roles)->build();
        $this->em()->persist($m);
        $this->em()->flush();

        return $m;
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

    private function token(string $selector): string
    {
        return (string) $this->client->getCrawler()->filter($selector.' input[name=_token]')->first()->attr('value');
    }

    private function team(Municipality $m, string $suffix = ''): string
    {
        return '/communes/'.$m->id()->toRfc4122().'/equipe'.$suffix;
    }

    /** @return list<Cookie> */
    private function saveSession(): array
    {
        return array_values($this->client->getCookieJar()->all());
    }

    /** @param list<Cookie> $cookies */
    private function restoreSession(array $cookies): void
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
    }

    /**
     * @param list<string> $roles
     */
    private function inviteVia(Municipality $m, string $email, array $roles, string $page = '/inviter'): void
    {
        $this->client->request('GET', $this->team($m, $page));
        $this->client->request('POST', $this->team($m, $page), ['_token' => $this->token('form[action$="/inviter"]'), 'email' => $email, 'roles' => $roles]);
    }

    /** L'invité (anonyme) ouvre son lien et crée son compte ; retourne l'URL d'arrivée. */
    private function acceptByLink(string $token, string $first = 'Agnès'): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/invitation/$token");
        $this->client->followRedirect();
        $this->client->request('POST', '/invitation/rejoindre', ['_token' => $this->token('form[action$="/invitation/rejoindre"]'), 'first_name' => $first, 'last_name' => 'Martin', 'password' => 'une phrase de passe longue', 'password_confirm' => 'une phrase de passe longue', 'attest' => '1']);
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @return list<string>
     */
    private function roles(string $email, Municipality $m): array
    {
        return (array) json_decode((string) $this->db()->fetchOne('SELECT mm.roles FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ? AND mm.municipality_id = ?', [$email, $m->id()->toBinary()]), true);
    }

    private function active(string $email, Municipality $m): bool
    {
        return 1 === (int) $this->db()->fetchOne('SELECT mm.active FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ? AND mm.municipality_id = ?', [$email, $m->id()->toBinary()]);
    }

    // ───────────────────────── Parcours complet ─────────────────────────

    public function testFullJourneyFromCwsOpeningToTheImmediateRemovalOfAnInstructor(): void
    {
        $this->createAccount('root@example.test', superAdmin: true);
        $this->em()->persist(new NationalCommune(new NationalCommuneRecord('33503', 'Saumos', '33', '75', ['33680'], 1800), 'test', EventFixtures::now()));
        $this->em()->flush();

        // 1. CWS ouvre la commune, attribue l'accès et invite l'administrateur (LOT 07.1).
        $this->login('root@example.test');
        $this->client->request('GET', '/admin/cws/communes/ouvrir?q=saumos');
        $this->client->request('POST', '/admin/cws/communes/ouvrir', ['_token' => $this->token('form[action$="/ouvrir"]'), 'insee' => '33503']);
        $fiche = (string) $this->client->getResponse()->headers->get('Location');
        $cid = basename($fiche);
        $this->client->request('GET', $fiche);
        $this->client->request('POST', "/admin/cws/communes/$cid/acces", ['_token' => $this->token('form[action$="/acces"]'), 'kind' => 'grant', 'reason' => 'Pilote', 'expires_on' => '']);
        $this->client->request('GET', $fiche);
        $this->client->request('POST', "/admin/cws/communes/$cid/administrateurs/inviter", ['_token' => $this->token('form[action$="/inviter"]'), 'email' => 'maire@saumos.example']);

        // 2. L'administrateur active son compte.
        $arrival = $this->acceptByLink($this->mails->lastToken(), 'Marie');
        self::assertMatchesRegularExpression('~^/communes/[0-9a-f-]{36}/evenements$~', $arrival);
        $commune = $this->em()->getRepository(Municipality::class)->findOneBy(['name' => 'Saumos']) ?? throw new \LogicException();
        self::assertSame(['MUNICIPALITY_ADMIN'], $this->roles('maire@saumos.example', $commune));

        // 3. Il invite un instructeur et un gestionnaire depuis l'écran Équipe (aucun accès à Sonata).
        $this->client->request('GET', $this->team($commune));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Équipe');
        $this->client->request('GET', '/admin/dashboard');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->team($commune));
        $this->inviteVia($commune, 'instructeur@saumos.example', ['INSTRUCTOR']);
        self::assertResponseRedirects($this->team($commune));
        $instructorToken = $this->mails->lastToken();
        $this->inviteVia($commune, 'gestionnaire@saumos.example', ['EVENT_MANAGER']);
        $managerToken = $this->mails->lastToken();
        self::assertSame('Marie Martin', $this->mails->sent[1]['inviter'] ?? 'x', 'le message nomme l’invitant');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['instructeur@saumos.example']), 'aucun compte, donc aucun droit, avant acceptation');
        $adminSession = $this->saveSession();
        $this->client->request('GET', $this->team($commune));
        self::assertSelectorTextContains('body', 'instructeur@saumos.example');
        self::assertSelectorTextContains('body', 'En attente');

        // 4. Ils acceptent : exactement les rôles invités ; leur menu et leurs accès s'y conforment.
        $this->acceptByLink($instructorToken, 'Ines');
        $instructorSession = $this->saveSession();
        self::assertSame(['INSTRUCTOR'], $this->roles('instructeur@saumos.example', $commune));
        $this->client->request('GET', '/communes/'.$commune->id()->toRfc4122().'/demandes');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->team($commune));
        self::assertResponseStatusCodeSame(403, 'un instructeur n’accède pas à l’équipe');
        $this->acceptByLink($managerToken, 'Marc');
        $this->client->request('GET', '/communes/'.$commune->id()->toRfc4122().'/evenements/nouveau');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->team($commune));
        self::assertResponseStatusCodeSame(403, 'un gestionnaire n’accède pas à l’équipe');

        // 5. L'administrateur modifie puis retire l'instructeur.
        $this->restoreSession($adminSession);
        $this->client->request('GET', $this->team($commune));
        $membershipId = (string) $this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(mm.id),1,8),\'-\',SUBSTR(HEX(mm.id),9,4),\'-\',SUBSTR(HEX(mm.id),13,4),\'-\',SUBSTR(HEX(mm.id),17,4),\'-\',SUBSTR(HEX(mm.id),21))) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ?', ['instructeur@saumos.example']);
        $this->client->request('GET', $this->team($commune, "/$membershipId/roles"));
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $this->team($commune, "/$membershipId/roles"), ['_token' => $this->token('form[action$="/roles"]'), 'roles' => ['INSTRUCTOR', 'EVENT_MANAGER']]);
        self::assertResponseRedirects($this->team($commune));
        self::assertSame(['EVENT_MANAGER', 'INSTRUCTOR'], $this->roles('instructeur@saumos.example', $commune));

        // 6. Coupure IMMÉDIATE : la session de l'instructeur est restée ouverte pendant le retrait.
        $this->restoreSession($instructorSession);
        $this->client->request('GET', '/communes/'.$commune->id()->toRfc4122().'/demandes');
        self::assertResponseIsSuccessful('avant le retrait, l’instructeur travaille');
        $this->restoreSession($adminSession);
        $this->client->request('GET', $this->team($commune, "/$membershipId/retirer"));
        self::assertSelectorTextContains('body', 'accès');
        $this->client->request('POST', $this->team($commune, "/$membershipId/retirer"), ['_token' => $this->token('form[action$="/retirer"]')]);
        self::assertResponseRedirects($this->team($commune));
        self::assertFalse($this->active('instructeur@saumos.example', $commune));
        $this->restoreSession($instructorSession);
        foreach (['/demandes', '/evenements', '/evenements/nouveau'] as $path) {
            $this->client->request('GET', '/communes/'.$commune->id()->toRfc4122().$path);
            self::assertResponseStatusCodeSame(404, "accès coupé immédiatement : $path");
        }
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT active FROM identity_user WHERE email = ?', ['instructeur@saumos.example']), 'le compte lui-même n’est pas désactivé');
    }

    // ───────────────────────── Accès par rôle ─────────────────────────

    public function testAccessMatrixAndWritePagesNeedFullAuthentication(): void
    {
        $this->world();
        $this->createAccount('root@example.test', superAdmin: true);
        $this->member('gest@mairie.example', $this->a, MunicipalityRole::EventManager);
        $instructor = $this->member('instr@mairie.example', $this->a, MunicipalityRole::Instructor);
        $paths = [$this->team($this->a), $this->team($this->a, '/inviter'), $this->team($this->a, '/'.$instructor->id()->toRfc4122().'/roles'), $this->team($this->a, '/'.$instructor->id()->toRfc4122().'/retirer')];

        foreach ($paths as $path) {
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/login', null, "anonyme $path");
        }
        foreach (['gest@mairie.example' => 403, 'instr@mairie.example' => 403, 'admin-b@mairie.example' => 404, 'root@example.test' => 404] as $email => $status) {
            $this->login($email);
            foreach ($paths as $path) {
                $this->client->request('GET', $path);
                self::assertResponseStatusCodeSame($status, "$email $path");
            }
        }
        // Session « mémorisée » seule : lecture possible ailleurs, mais les pages d'écriture exigent le mot de passe.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['email' => 'admin-a@mairie.example', 'password' => self::PASSWORD, '_remember_me' => '1']);
        $remember = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($remember);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        $this->client->request('GET', $this->team($this->a, '/inviter'));
        self::assertResponseRedirects('/login');
        $this->client->request('POST', $this->team($this->a, '/inviter'), ['email' => 'x@y.example', 'roles' => ['INSTRUCTOR']]);
        self::assertResponseRedirects('/login');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation'));
    }

    public function testEveryWriteNeedsItsOwnCsrfTokenAndGetNeverWrites(): void
    {
        $this->world();
        $agent = $this->member('agent@mairie.example', $this->a, MunicipalityRole::Instructor);
        $other = $this->member('autre@mairie.example', $this->a, MunicipalityRole::Instructor);
        $this->login('admin-a@mairie.example');
        $aid = $agent->id()->toRfc4122();
        $audit = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log');
        foreach (['/inviter', "/$aid/roles", "/$aid/retirer"] as $path) { // GET : aucune écriture
            $this->client->request('GET', $this->team($this->a, $path));
            self::assertResponseIsSuccessful($path);
        }
        self::assertSame($audit, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_log'));

        // Jeton d'une AUTRE cible : refusé.
        $this->client->request('GET', $this->team($this->a, '/'.$other->id()->toRfc4122().'/retirer'));
        $otherToken = $this->token('form[action$="/retirer"]');
        $this->client->request('POST', $this->team($this->a, "/$aid/retirer"), ['_token' => $otherToken]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'expiré');
        $this->client->request('POST', $this->team($this->a, "/$aid/roles"), ['_token' => 'forgé', 'roles' => ['MUNICIPALITY_ADMIN']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', $this->team($this->a, '/inviter'), ['email' => 'z@mairie.example', 'roles' => ['INSTRUCTOR']]);
        self::assertResponseStatusCodeSame(422);

        self::assertTrue($this->active('agent@mairie.example', $this->a));
        self::assertSame(['INSTRUCTOR'], $this->roles('agent@mairie.example', $this->a), 'aucune élévation sans jeton valide');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation'));
    }

    // ───────────────────────── Périmètre et confidentialité ─────────────────────────

    public function testForgedAndForeignIdentifiersAreUniform404s(): void
    {
        $this->world();
        $foreign = $this->member('etranger@commune-b.example', $this->b, MunicipalityRole::Instructor);
        $this->login('admin-b@mairie.example');
        $this->inviteVia($this->b, 'invite-b@commune-b.example', ['INSTRUCTOR']);
        $foreignInvitation = (string) $this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM municipality_admin_invitation');
        $this->login('admin-a@mairie.example');
        $fid = $foreign->id()->toRfc4122();
        $unknown = '0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000';

        foreach ([
            ['GET', "/$fid/roles"], ['GET', "/$fid/retirer"], ['POST', "/$fid/retirer"], ['POST', "/$fid/roles"],
            ['GET', "/$unknown/roles"], ['POST', "/$unknown/retirer"],
            ['GET', "/invitations/$foreignInvitation/revoquer"], ['POST', "/invitations/$foreignInvitation/revoquer"], ['POST', "/invitations/$foreignInvitation/renvoyer"],
            ['GET', "/invitations/$unknown/revoquer"],
        ] as [$method, $suffix]) {
            $this->client->request($method, $this->team($this->a, $suffix), ['roles' => ['MUNICIPALITY_ADMIN']]);
            self::assertResponseStatusCodeSame(404, "$method $suffix");
        }
        // Commune étrangère dans l'URL : même 404.
        $this->client->request('GET', $this->team($this->b));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->team($this->b, '/inviter'), ['email' => 'x@y.example', 'roles' => ['INSTRUCTOR']]);
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->active('etranger@commune-b.example', $this->b));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM municipality_admin_invitation WHERE revoked_at IS NULL'), 'l’invitation de B est intacte');
    }

    public function testTheTeamPageNeverShowsAnotherCommunesPeopleOrLeaksAccountsElsewhere(): void
    {
        $this->world();
        $this->member('secret-b@commune-b.example', $this->b, MunicipalityRole::Instructor);
        $agent = $this->member('partage@mairie.example', $this->a, MunicipalityRole::Instructor);
        $this->em()->persist(MembershipBuilder::aMembership($agent->user(), $this->b)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $this->em()->flush();
        $this->login('admin-a@mairie.example');
        $this->client->request('GET', $this->team($this->a));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('secret-b@commune-b.example', $html);
        self::assertStringNotContainsString('admin-b@mairie.example', $html);
        self::assertStringContainsString('partage@mairie.example', $html);
        self::assertStringNotContainsString('Administrateur communal', substr($html, (int) strpos($html, 'partage@mairie.example'), 300), 'ses rôles de la commune B ne sont pas montrés');

        // Invitation d'un compte actif d'une autre commune : aucune différence visible avec une adresse inconnue.
        $this->inviteVia($this->a, 'admin-b@mairie.example', ['INSTRUCTOR']);
        self::assertResponseRedirects($this->team($this->a));
        $this->inviteVia($this->a, 'inconnu@example.test', ['INSTRUCTOR']);
        self::assertResponseRedirects($this->team($this->a));
    }

    public function testACwsFicheNeverShowsOrdinaryAgentInvitations(): void
    {
        $this->world();
        $this->createAccount('root@example.test', superAdmin: true);
        $this->login('admin-a@mairie.example');
        $this->inviteVia($this->a, 'agent-secret@mairie.example', ['INSTRUCTOR']);
        $this->inviteVia($this->a, 'second-admin@mairie.example', ['MUNICIPALITY_ADMIN', 'INSTRUCTOR']);
        $this->login('root@example.test');
        $this->client->request('GET', '/admin/cws/communes/'.$this->a->id()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('agent-secret@mairie.example', $html);
        self::assertStringContainsString('second-admin@mairie.example', $html);
        $agentInvitation = (string) $this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM municipality_admin_invitation WHERE recipient_email = ?', ['agent-secret@mairie.example']);
        $this->client->request('GET', "/admin/cws/confirmer/invitation_revoke/$agentInvitation");
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('agent-secret', (string) $this->db()->fetchOne('SELECT GROUP_CONCAT(action) FROM audit_log') . $html);
    }

    // ───────────────────────── Règles d'équipe ─────────────────────────

    public function testSuspendedCommuneIsReadOnly(): void
    {
        $this->world();
        $agent = $this->member('agent@mairie.example', $this->a, MunicipalityRole::Instructor);
        $this->em()->getConnection()->executeStatement('UPDATE municipality SET status = ? WHERE id = ?', [MunicipalityStatus::Suspended->value, $this->a->id()->toBinary()]);
        $this->em()->clear();
        $this->login('admin-a@mairie.example');
        $this->client->request('GET', $this->team($this->a));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'lecture seule');
        self::assertStringNotContainsString('/retirer', (string) $this->client->getResponse()->getContent());
        foreach ([['GET', '/inviter'], ['POST', '/inviter'], ['GET', '/'.$agent->id()->toRfc4122().'/roles'], ['POST', '/'.$agent->id()->toRfc4122().'/retirer']] as [$method, $suffix]) {
            $this->client->request($method, $this->team($this->a, $suffix), ['email' => 'x@y.example', 'roles' => ['INSTRUCTOR']]);
            self::assertResponseStatusCodeSame(403, "$method $suffix");
        }
        self::assertTrue($this->active('agent@mairie.example', $this->a));
    }

    public function testNoSelfServiceAndTheLastAdministratorIsProtectedInTheInterface(): void
    {
        $this->world();
        $peer = $this->member('pair@mairie.example', $this->a, MunicipalityRole::MunicipalityAdmin);
        $self = $this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(mm.id),1,8),\'-\',SUBSTR(HEX(mm.id),9,4),\'-\',SUBSTR(HEX(mm.id),13,4),\'-\',SUBSTR(HEX(mm.id),17,4),\'-\',SUBSTR(HEX(mm.id),21))) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ?', ['admin-a@mairie.example']);
        $this->login('admin-a@mairie.example');

        // Pas d'action sur soi dans la liste ; la tentative directe est refusée avec un message.
        $this->client->request('GET', $this->team($this->a));
        self::assertStringNotContainsString("/$self/", (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->team($this->a, "/$self/roles"));
        $this->client->request('POST', $this->team($this->a, "/$self/roles"), ['_token' => $this->token('form[action$="/roles"]'), 'roles' => ['INSTRUCTOR']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'propres');
        $this->client->request('GET', $this->team($this->a, "/$self/retirer"));
        $this->client->request('POST', $this->team($this->a, "/$self/retirer"), ['_token' => $this->token('form[action$="/retirer"]')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'vous retirer vous-même');
        self::assertTrue($this->active('admin-a@mairie.example', $this->a));
        self::assertSame(['MUNICIPALITY_ADMIN'], $this->roles('admin-a@mairie.example', $this->a));

        // Un pair peut être retiré (A2) ; ensuite le dernier administrateur est protégé et l'écran l'explique.
        $peerId = $peer->id()->toRfc4122();
        $this->client->request('GET', $this->team($this->a, "/$peerId/retirer"));
        $this->client->request('POST', $this->team($this->a, "/$peerId/retirer"), ['_token' => $this->token('form[action$="/retirer"]')]);
        self::assertFalse($this->active('pair@mairie.example', $this->a));
        $this->client->request('GET', $this->team($this->a));
        self::assertSelectorTextContains('body', 'Une commune doit toujours garder au moins un administrateur actif');
    }

    public function testAnAdministratorCannotBeDemotedByAnAdministratorWhoNoLongerIs(): void
    {
        $this->world();
        $peer = $this->member('pair@mairie.example', $this->a, MunicipalityRole::MunicipalityAdmin);
        $peerId = $peer->id()->toRfc4122();
        $this->login('pair@mairie.example');
        $this->client->request('GET', $this->team($this->a, '/'.$this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(mm.id),1,8),\'-\',SUBSTR(HEX(mm.id),9,4),\'-\',SUBSTR(HEX(mm.id),13,4),\'-\',SUBSTR(HEX(mm.id),17,4),\'-\',SUBSTR(HEX(mm.id),21))) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ?', ['admin-a@mairie.example']).'/retirer'));
        $token = $this->token('form[action$="/retirer"]');
        $session = $this->saveSession();
        // Un autre administrateur rétrograde le pair pendant que sa session et son formulaire sont ouverts.
        $this->login('admin-a@mairie.example');
        $this->client->request('GET', $this->team($this->a, "/$peerId/roles"));
        $this->client->request('POST', $this->team($this->a, "/$peerId/roles"), ['_token' => $this->token('form[action$="/roles"]'), 'roles' => ['INSTRUCTOR']]);
        self::assertSame(['INSTRUCTOR'], $this->roles('pair@mairie.example', $this->a));
        // Le pair soumet son ancien formulaire : refusé, d'après l'état courant.
        $this->restoreSession($session);
        $adminId = (string) $this->db()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(mm.id),1,8),\'-\',SUBSTR(HEX(mm.id),9,4),\'-\',SUBSTR(HEX(mm.id),13,4),\'-\',SUBSTR(HEX(mm.id),17,4),\'-\',SUBSTR(HEX(mm.id),21))) FROM municipality_membership mm JOIN identity_user u ON u.id = mm.user_id WHERE u.email = ?', ['admin-a@mairie.example']);
        $this->client->request('POST', $this->team($this->a, "/$adminId/retirer"), ['_token' => $token]);
        self::assertResponseStatusCodeSame(403, 'rétrogradé : n’est plus administrateur, l’équipe lui est fermée');
        self::assertTrue($this->active('admin-a@mairie.example', $this->a));
    }
}
