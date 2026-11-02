<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Admin\MembershipAdmin;
use App\Admin\MunicipalityAdmin;
use App\Admin\Security\ReadOnlySecurityHandler;
use App\Admin\UserAdmin;
use App\Identity\Application\PasswordResetService;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Mail\DeferredMailQueue;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\WebTestCase;
use Sonata\AdminBundle\Admin\Pool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Back-office CWS (Sonata) : accès, lecture seule stricte, absence de fuite, UUID dans les URLs, navigation.
 */
final class SonataBackOfficeTest extends WebTestCase
{
    private User $root;
    private User $municipalAdmin;
    private User $plain;
    private User $agentB;
    private Municipality $communeA;
    private Municipality $communeB;
    private MunicipalityMembership $adminMembership;
    private MunicipalityMembership $agentMembership;

    /**
     * @param array<string, mixed> $options options du noyau
     */
    private function world(array $options = []): KernelBrowser
    {
        $client = $this->freshClient($options);
        $this->root = $this->createAccount('root@example.test', superAdmin: true);
        $this->municipalAdmin = $this->createAccount('maire@example.test');
        $this->plain = $this->createAccount('simple@example.test');
        $this->agentB = $this->createAccount('agent-b@example.test');
        $this->communeA = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $this->communeB = MunicipalityBuilder::aMunicipality()->withInseeCode('69123')->build();
        $this->adminMembership = MembershipBuilder::aMembership($this->municipalAdmin, $this->communeA)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->agentMembership = MembershipBuilder::aMembership($this->agentB, $this->communeB)->withRoles(MunicipalityRole::Instructor, MunicipalityRole::EventManager)->build();
        foreach ([$this->communeA, $this->communeB, $this->adminMembership, $this->agentMembership] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $client;
    }

    /**
     * @return list<string> toutes les pages de lecture du back-office
     */
    private function readUrls(): array
    {
        return [
            '/admin/', '/admin/dashboard', '/admin/search?q=test',
            '/admin/identity/user/list', '/admin/identity/user/'.$this->root->id()->toRfc4122().'/show',
            '/admin/communes/commune/list', '/admin/communes/commune/'.$this->communeA->id()->toRfc4122().'/show',
            '/admin/communes/appartenance/list', '/admin/communes/appartenance/'.$this->adminMembership->id()->toRfc4122().'/show',
        ];
    }

    /**
     * @return list<string> routes techniques de Sonata fermées à tous
     */
    private function closedCoreUrls(): array
    {
        return [
            '/admin/core/set-object-field-value', '/admin/core/append-form-field-element', '/admin/core/get-form-field-element',
            '/admin/core/get-autocomplete-items', '/admin/core/get-short-object-description.json',
        ];
    }

    /**
     * @return list<string> routes d'écriture qui NE doivent PAS exister, pour chaque admin
     */
    private function writeUrls(): array
    {
        $urls = [];
        $targets = ['identity/user' => $this->root->id(), 'communes/commune' => $this->communeA->id(), 'communes/appartenance' => $this->adminMembership->id()];
        foreach ($targets as $base => $id) {
            foreach (['create', 'export', 'batch', 'acl'] as $suffix) {
                $urls[] = "/admin/$base/$suffix";
            }
            foreach (['edit', 'delete', 'history', 'acl'] as $suffix) {
                $urls[] = "/admin/$base/".$id->toRfc4122().'/'.$suffix;
            }
        }

        return $urls;
    }

    private function login(KernelBrowser $client, string $email, bool $remember = false): void
    {
        $client->request('GET', '/login');
        $fields = ['email' => $email, 'password' => self::PASSWORD];
        if ($remember) {
            $fields['_remember_me'] = '1';
        }
        $client->submitForm('Se connecter', $fields);
    }

    // ───────────────────────── Accès ─────────────────────────

    public function testAnonymousIsSentToTheLoginPageEverywhere(): void
    {
        $client = $this->world();

        foreach ([...$this->readUrls(), ...$this->closedCoreUrls()] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, 'GET '.$url);
        }
        // Les routes d'écriture n'existent pas : 404 pour tout le monde (le routeur répond avant la sécurité), jamais une page.
        foreach ($this->writeUrls() as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, 'GET '.$url);
        }
        foreach (['/admin/dashboard', '/admin/identity/user/list', '/admin/core/set-object-field-value'] as $url) {
            $client->request('POST', $url);
            self::assertResponseRedirects('/login', 302, 'POST '.$url);
        }
    }

    public function testSimpleUserAndMunicipalAdministratorAreForbiddenEverywhere(): void
    {
        $client = $this->world();

        foreach (['simple@example.test' => 'utilisateur simple', 'maire@example.test' => 'administrateur communal (avec membership)', 'agent-b@example.test' => 'instructeur communal'] as $email => $label) {
            $client->getCookieJar()->clear();
            $this->login($client, $email);
            foreach ([...$this->readUrls(), ...$this->closedCoreUrls()] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(403, $label.' : GET '.$url);
            }
            foreach (['/admin/dashboard', '/admin/identity/user/list'] as $url) {
                $client->request('POST', $url);
                self::assertResponseStatusCodeSame(403, $label.' : POST '.$url);
            }
        }
    }

    public function testASessionThatIsOnlyRememberMeCannotReachTheBackOffice(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test', remember: true);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful('précondition : connexion complète');

        // Fin de session (navigateur fermé) : seul le cookie persistant subsiste.
        $remember = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($remember);
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', $remember->getValue(), null, '/', 'localhost'));

        foreach ($this->readUrls() as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, 'remember-me seul : '.$url);
        }

        // L'authentification complète rétablit l'accès.
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful('la page de connexion reste accessible (pas de boucle)');
        $client->submitForm('Se connecter', ['email' => 'root@example.test', 'password' => self::PASSWORD]);
        $client->request('GET', '/admin/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testADeactivatedAccountLosesTheBackOfficeImmediately(): void
    {
        $client = $this->world();
        $second = $this->createAccount('second-root@example.test', superAdmin: true);
        $this->login($client, 'second-root@example.test');
        $client->request('GET', '/admin/identity/user/list');
        self::assertResponseIsSuccessful();

        $this->em()->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$second->id()->toBinary()]);

        foreach ($this->readUrls() as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, $url);
        }
    }

    public function testASuperAdminWithFullAuthenticationReachesEveryReadPage(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach ($this->readUrls() as $url) {
            $client->request('GET', $url);
            if ('/admin/' === $url) {
                self::assertResponseRedirects('/admin/dashboard', null, $url);
                continue;
            }
            self::assertResponseStatusCodeSame(200, $url);
        }

        $page = $client->request('GET', '/admin/dashboard');
        $logo = $page->filter('a.logo');
        self::assertSame('BO Civipass', trim($logo->text()), 'Titre du back-office.');
        self::assertSame('BO Civipass', $logo->filter('img')->attr('alt'));
        self::assertMatchesRegularExpression('~^/assets/images/civipass-monogram-[\w-]+\.svg$~', (string) $logo->filter('img')->attr('src'), 'Logo : monogramme du design system, plus le logo Sonata par défaut.');
        self::assertStringNotContainsString('logo_title.png', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('<title>', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('BO Civipass', $page->filter('title')->text());
        foreach (['/admin/identity/user/list' => 'Utilisateurs', '/admin/communes/commune/list' => 'Communes', '/admin/communes/appartenance/list' => 'Appartenances communales'] as $href => $label) {
            self::assertGreaterThan(0, $page->filter('a[href="'.$href.'"]')->count(), $label.' sur le tableau de bord');
        }
        self::assertCount(0, $page->filter('a[href*="/create"], a[href*="/edit"], a[href*="/delete"], a[href*="/export"]'), 'Aucune action d\'écriture proposée.');
        self::assertCount(1, $page->filter('form[action="/logout"] input[name="_csrf_token"]'), 'Déconnexion POST + CSRF.');
    }

    // ───────────────────────── Lecture seule stricte ─────────────────────────

    public function testWriteRoutesDoNotExistEvenForASuperAdmin(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach ($this->writeUrls() as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, 'GET '.$url);
        }

        $names = array_keys(static::getContainer()->get('router')->getRouteCollection()->all());
        $adminRoutes = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'admin_')));
        sort($adminRoutes);
        self::assertSame([
            'admin_communes_appartenance_list', 'admin_communes_appartenance_show',
            'admin_communes_commune_list', 'admin_communes_commune_show',
            'admin_identity_user_list', 'admin_identity_user_show',
        ], $adminRoutes, 'Seules la liste et la consultation existent pour chaque admin.');
    }

    public function testEveryWriteMethodIsRefusedByTheServerEvenForASuperAdmin(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ([...$this->readUrls(), ...$this->closedCoreUrls()] as $url) {
                $client->request($method, $url);
                self::assertResponseStatusCodeSame(403, $method.' '.$url);
            }
            // Routes d'écriture : n'existent pas du tout.
            foreach ($this->writeUrls() as $url) {
                $client->request($method, $url);
                self::assertResponseStatusCodeSame(404, $method.' '.$url);
            }
        }
        // Aucune donnée n'a bougé.
        self::assertSame('root@example.test', $this->em()->getConnection()->fetchOne('SELECT email FROM identity_user WHERE id = ?', [$this->root->id()->toBinary()]));
    }

    public function testTechnicalSonataEndpointsAreClosedEvenForASuperAdmin(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach ($this->closedCoreUrls() as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, 'GET '.$url);
        }
    }

    public function testNoBatchExportOrInlineEditingIsRenderedOnTheLists(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach (['/admin/identity/user/list', '/admin/communes/commune/list', '/admin/communes/appartenance/list'] as $url) {
            $page = $client->request('GET', $url);
            self::assertCount(0, $page->filter('input[name="all_elements"], input[name="idx[]"], select[name="action"], .batch'), $url.' : pas d\'actions groupées');
            self::assertCount(0, $page->filter('a[href*="/export"], a[href*="format="]'), $url.' : pas d\'export');
            self::assertCount(0, $page->filter('[data-sonata-xeditable], .x-editable, a.sonata-ba-action[href*="edit"]'), $url.' : pas d\'édition inline');
            self::assertCount(0, $page->filter('form[method="post"]:not([action="/logout"])'), $url.' : aucun formulaire d\'écriture (hors déconnexion)');
        }
    }

    public function testTheSecurityHandlerOnlyEverGrantsReadPermissions(): void
    {
        $this->world();
        $container = static::getContainer();
        $tokens = $container->get('security.token_storage');
        $handler = $container->get(ReadOnlySecurityHandler::class);
        $admin = $container->get(Pool::class)->getAdminByClass(User::class);
        self::assertNotNull($admin);

        // Superadmin : lecture accordée, TOUT le reste refusé.
        $tokens->setToken(new UsernamePasswordToken($this->root, 'main', $this->root->getRoles()));
        foreach (['LIST', 'VIEW'] as $read) {
            self::assertTrue($handler->isGranted($admin, $read), $read);
        }
        foreach (['CREATE', 'EDIT', 'DELETE', 'UNDELETE', 'EXPORT', 'HISTORY', 'OPERATOR', 'MASTER', 'OWNER', 'ALL'] as $write) {
            self::assertFalse($handler->isGranted($admin, $write), $write.' doit être refusé même au superadmin');
        }

        // Utilisateur ordinaire : rien.
        $tokens->setToken(new UsernamePasswordToken($this->plain, 'main', $this->plain->getRoles()));
        self::assertFalse($handler->isGranted($admin, 'LIST'));
        self::assertFalse($handler->isGranted($admin, 'VIEW'));

        // Moindre privilège : un rôle par admin ouvre CE LIST-là et rien d'autre, jamais l'écriture.
        $listRole = sprintf($handler->getBaseRole($admin), 'LIST');
        $editRole = sprintf($handler->getBaseRole($admin), 'EDIT');
        $tokens->setToken(new UsernamePasswordToken($limited = new InMemoryUser('limited', null, ['ROLE_USER', $listRole, $editRole]), 'main', $limited->getRoles()));
        self::assertTrue($handler->isGranted($admin, 'LIST'));
        self::assertFalse($handler->isGranted($admin, 'VIEW'));
        self::assertFalse($handler->isGranted($admin, 'EDIT'), 'Le rôle EDIT existe mais la permission est refusée.');
        self::assertFalse($handler->isGranted($container->get(Pool::class)->getAdminByClass(Municipality::class), 'LIST'), 'Un rôle ne vaut que pour son admin.');
        self::assertSame([], array_diff(array_keys($handler->buildSecurityInformation($admin)), ['LIST', 'VIEW']), 'Informations de sécurité limitées à la lecture.');
    }

    public function testAdminsAreRegisteredWithTheReadOnlySecurityHandler(): void
    {
        $this->world();
        $pool = static::getContainer()->get(Pool::class);

        foreach ([User::class => UserAdmin::class, Municipality::class => MunicipalityAdmin::class, MunicipalityMembership::class => MembershipAdmin::class] as $model => $adminClass) {
            $admin = $pool->getAdminByClass($model);
            self::assertInstanceOf($adminClass, $admin);
            self::assertInstanceOf(ReadOnlySecurityHandler::class, $admin->getSecurityHandler(), 'Le gestionnaire configuré est bien le gestionnaire en lecture seule (pas « noop »).');
            self::assertSame([], $admin->getExportFormats());
            self::assertSame([], $admin->getBatchActions());
        }
        self::assertCount(3, $pool->getAdminServiceCodes(), 'Trois modules : aucun module Abonnements tant que l\'entité n\'existe pas.');
    }

    // ───────────────────────── Fuite de données ─────────────────────────

    public function testNoPasswordHashOrTokenIsEverDisplayed(): void
    {
        $client = $this->world();
        // Un jeton de réinitialisation existe en base : son empreinte ne doit apparaître nulle part.
        static::getContainer()->get(PasswordResetService::class)->requestReset('simple@example.test');
        static::getContainer()->get(DeferredMailQueue::class)->flush();
        $this->login($client, 'root@example.test', remember: true);

        $secrets = array_merge(
            $this->em()->getConnection()->fetchFirstColumn('SELECT password FROM identity_user'),
            $this->em()->getConnection()->fetchFirstColumn('SELECT token_hash FROM identity_password_reset_request'),
        );
        self::assertGreaterThanOrEqual(5, \count($secrets));
        $rememberCookie = $client->getCookieJar()->get('REMEMBERME');

        foreach ($this->readUrls() as $url) {
            $client->request('GET', $url);
            $html = (string) $client->getResponse()->getContent();
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString((string) $secret, $html, $url.' : empreinte de mot de passe ou de jeton affichée.');
            }
            self::assertDoesNotMatchRegularExpression('/\$(2[aby]|argon2(id|i)?)\$/', $html, $url.' : hash de mot de passe.');
            self::assertStringNotContainsString('token_hash', $html, $url);
            self::assertStringNotContainsString('REMEMBERME', $html, $url);
            if (null !== $rememberCookie) {
                self::assertStringNotContainsString($rememberCookie->getValue(), $html, $url);
            }
        }
    }

    public function testOnlyTheExplicitlyAllowedFieldsAreShown(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');
        $label = static fn (Crawler $page, string $css): array => $page->filter($css)->each(static fn (Crawler $n): string => trim((string) preg_replace('/\s+/', ' ', $n->text())));

        $list = $client->request('GET', '/admin/identity/user/list');
        self::assertSame(['Adresse email', 'Nom', 'Prénom', 'Actif', 'Superadmin', 'Dernière connexion', 'Créé le'], $label($list, 'table thead th'));

        $show = $client->request('GET', '/admin/identity/user/'.$this->plain->id()->toRfc4122().'/show');
        self::assertSame(['Identifiant', 'Adresse email', 'Nom', 'Prénom', 'Compte actif', 'Superadmin CWS', 'Dernière connexion', 'Créé le', 'Modifié le'], $label($show, '.sonata-ba-view th'));
        self::assertStringNotContainsStringIgnoringCase('mot de passe', $show->text());
        self::assertStringNotContainsStringIgnoringCase('password', $show->text());

        $show = $client->request('GET', '/admin/communes/appartenance/'.$this->agentMembership->id()->toRfc4122().'/show');
        self::assertSame(['Identifiant', 'Utilisateur', 'Commune', 'Rôles communaux', 'Appartenance active', 'Créée le', 'Modifiée le'], $label($show, '.sonata-ba-view th'));
    }

    public function testNoAdminExistsForAnOtherEntityAndTheirRoutesAreAbsent(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        // Ni jetons de réinitialisation, ni journal d'audit, ni abonnements : aucune route d'admin.
        foreach (['/admin/identity/password-reset-request/list', '/admin/shared/audit-log/list', '/admin/communes/abonnement/list', '/admin/identity/reset-request/list'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testRefusalPagesDoNotLeakBackOfficeData(): void
    {
        // Debug désactivé : ce sont les vraies pages d'erreur (la page de debug reprend le code source des tests dans sa trace).
        $client = $this->world(['debug' => false]);
        $this->login($client, 'maire@example.test');

        foreach ($this->readUrls() as $url) {
            $client->request('GET', $url);
            $html = (string) $client->getResponse()->getContent();
            self::assertResponseStatusCodeSame(403, $url);
            foreach (['root@example.test', 'agent-b@example.test', 'Commune 75056', 'Commune 69123', 'Administrateur communal', 'Utilisateurs', 'Appartenances', 'sonata'] as $secret) {
                self::assertStringNotContainsStringIgnoringCase($secret, $html, $url.' : donnée ou structure du back-office dans une page de refus.');
            }
        }
    }

    public function testBrowsingNeverModifiesAnyData(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');
        $connection = $this->em()->getConnection();
        $snapshot = static fn (): array => [
            $connection->fetchFirstColumn('SELECT CONCAT(email, active, super_admin, IFNULL(last_login_at, ""), updated_at) FROM identity_user ORDER BY email'),
            $connection->fetchFirstColumn('SELECT CONCAT(HEX(id), status, updated_at) FROM municipality ORDER BY id'),
            $connection->fetchFirstColumn('SELECT CONCAT(HEX(id), roles, active, updated_at) FROM municipality_membership ORDER BY id'),
            [(int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log')],
        ];
        $before = $snapshot();

        foreach ([...$this->readUrls(), '/admin/identity/user/list?filter[active][value]=1', '/admin/communes/commune/list?filter[_sort_by]=inseeCode'] as $url) {
            $client->request('GET', $url);
        }

        self::assertSame($before, $snapshot());
    }

    // ───────────────────────── UUID dans les URLs ─────────────────────────

    public function testUuidIdentifiersWorkInUrlsAndBadOnesAreCleanNotFounds(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');

        foreach (['identity/user' => $this->plain, 'communes/commune' => $this->communeA, 'communes/appartenance' => $this->adminMembership] as $base => $entity) {
            $uuid = $entity->id()->toRfc4122();
            $client->request('GET', "/admin/$base/$uuid/show");
            self::assertResponseStatusCodeSame(200, $base.' avec UUID');
            self::assertStringContainsString($uuid, (string) $client->getResponse()->getContent());

            foreach (['00000000-0000-7000-8000-000000000000', 'pas-un-uuid', '1', "' OR 1=1 --", '..%2F..%2Fadmin'] as $bad) {
                $client->request('GET', "/admin/$base/".$bad.'/show');
                self::assertResponseStatusCodeSame(404, $base.' avec « '.$bad.' » : 404 propre attendu, pas de 500');
            }
        }
    }

    // ───────────────────────── Filtres, recherche, tri ─────────────────────────

    public function testSearchFiltersAndSortingWork(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');
        $rows = static fn (Crawler $page): array => $page->filter('table tbody tr')->each(static fn (Crawler $r): string => trim((string) preg_replace('/\s+/', ' ', $r->text())));

        $page = $client->request('GET', '/admin/identity/user/list?filter[email][value]=maire&filter[email][type]=1');
        self::assertCount(1, $rows($page));
        self::assertStringContainsString('maire@example.test', $rows($page)[0]);

        $page = $client->request('GET', '/admin/identity/user/list?filter[superAdmin][value]=1');
        self::assertCount(1, $rows($page));
        self::assertStringContainsString('root@example.test', $rows($page)[0]);

        $page = $client->request('GET', '/admin/identity/user/list?filter[_sort_by]=email&filter[_sort_order]=ASC');
        $emails = array_map(static fn (string $r): string => explode(' ', $r)[0], $rows($page));
        $sorted = $emails;
        sort($sorted);
        self::assertSame($sorted, $emails, 'Tri par adresse email croissant.');

        $page = $client->request('GET', '/admin/communes/commune/list?filter[inseeCode][value]=69123&filter[inseeCode][type]=3');
        self::assertCount(1, $rows($page));
        self::assertStringContainsString('Commune 69123', $rows($page)[0]);

        $page = $client->request('GET', '/admin/communes/commune/list?filter[status][value]=SUSPENDED');
        self::assertSame([], $rows($page), 'Aucune commune suspendue.');

        $page = $client->request('GET', '/admin/communes/appartenance/list?filter[municipality__name][value]=69123&filter[municipality__name][type]=1');
        self::assertCount(1, $rows($page));
        self::assertStringContainsString('agent-b@example.test', $rows($page)[0]);
    }

    // ───────────────────────── Navigation entre modules ─────────────────────────

    public function testNavigationBetweenUsersMunicipalitiesAndMemberships(): void
    {
        $client = $this->world();
        $this->login($client, 'root@example.test');
        $rows = static fn (Crawler $page): array => $page->filter('table tbody tr')->each(static fn (Crawler $r): string => trim((string) preg_replace('/\s+/', ' ', $r->text())));

        // Utilisateur → ses appartenances (et seulement les siennes).
        $user = $client->request('GET', '/admin/identity/user/'.$this->agentB->id()->toRfc4122().'/show');
        $client->click($user->selectLink('Appartenances communales')->last()->link());
        self::assertResponseIsSuccessful();
        $list = $client->getCrawler();
        self::assertCount(1, $rows($list));
        self::assertStringContainsString('agent-b@example.test', $rows($list)[0]);
        self::assertStringNotContainsString('maire@example.test', $list->text());

        // Commune → ses appartenances (et seulement les siennes).
        $commune = $client->request('GET', '/admin/communes/commune/'.$this->communeA->id()->toRfc4122().'/show');
        $client->click($commune->selectLink('Appartenances de la commune')->link());
        self::assertResponseIsSuccessful();
        $list = $client->getCrawler();
        self::assertCount(1, $rows($list));
        self::assertStringContainsString('maire@example.test', $rows($list)[0]);
        self::assertStringNotContainsString('agent-b@example.test', $list->text());

        // Appartenance → fiche utilisateur et fiche commune, rôles en clair.
        $membership = $client->request('GET', '/admin/communes/appartenance/'.$this->agentMembership->id()->toRfc4122().'/show');
        self::assertStringContainsString('Responsable d’événement, Instructeur', $membership->text());
        $client->click($membership->selectLink('agent-b@example.test')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('agent-b@example.test', $client->getCrawler()->filter('.sonata-ba-view')->text());

        $membership = $client->request('GET', '/admin/communes/appartenance/'.$this->agentMembership->id()->toRfc4122().'/show');
        $client->click($membership->selectLink('Commune 69123')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('69123', $client->getCrawler()->filter('.sonata-ba-view')->text());

        // Liste des appartenances → liens vers les fiches.
        $list = $client->request('GET', '/admin/communes/appartenance/list');
        self::assertGreaterThan(0, $list->filter('table tbody a[href*="/admin/identity/user/"]')->count());
        self::assertGreaterThan(0, $list->filter('table tbody a[href*="/admin/communes/commune/"]')->count());
        self::assertGreaterThan(0, $list->filter('table tbody a[href*="/appartenance/"][href$="/show"]')->count());
    }

    // ───────────────────────── Supervision CWS inchangée ─────────────────────────

    public function testSuperAdminHasNoImplicitCommunalRoleAndTheLot01SupervisionIsUnchanged(): void
    {
        $this->world();
        $policy = static::getContainer()->get(MembershipAccessPolicy::class);

        self::assertSame(['ROLE_USER', 'ROLE_SUPER_ADMIN'], $this->root->getRoles());
        foreach (MunicipalityRole::cases() as $role) {
            self::assertFalse($policy->hasRole($this->root, $this->communeA, $role), 'Aucun rôle communal implicite : '.$role->value);
        }
        // Les trois permissions de supervision du LOT 01, conservées telles quelles.
        self::assertTrue($policy->canManageMembers($this->root, $this->communeA));
        self::assertTrue($policy->canEditContact($this->root, $this->communeA));
        self::assertTrue($policy->canReadAudit($this->root, $this->communeA));
        // Un administrateur de commune n'a pas accès à une autre commune.
        self::assertFalse($policy->canManageMembers($this->municipalAdmin, $this->communeB));
    }
}
