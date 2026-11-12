<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rights;

use App\Rights\Domain\RightsRequestKind;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RightsScenarioTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Droits des personnes dans le navigateur simulé (LOT 05.6.6) : matrice des rôles et des communes, 404 uniforme avant le jeton CSRF, identifiants forgés, aucune donnée de personne
 * affichée, lien de navigation réservé, parcours complet par les formulaires, courses entre décisions.
 */
final class RightsAccessTest extends RightsScenarioTestCase
{
    private const ROUTES = ['app_rights_index', 'app_rights_new', 'app_rights_show', 'app_rights_attest', 'app_rights_extend', 'app_rights_decide', 'app_rights_close', 'app_rights_mention', 'app_rights_erase', 'app_rights_export'];

    private function url(string $municipalityId, string $suffix = ''): string
    {
        return '/communes/'.$municipalityId.'/droits'.$suffix;
    }

    public function testEveryRightsRouteIsInTheMatrixAndReservedOutsideTheClosureBarrier(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $found = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/communes/{municipalityId}/droits')) {
                $found[] = $name;
                self::assertSame(\App\Event\UI\Http\RouteClosure::Reserved, (new \App\Event\UI\Http\ClosedEventRouteClassifier())->classify($route->getPath()));
            }
        }
        self::assertEqualsCanonicalizing(self::ROUTES, $found, 'toute nouvelle route de droits doit entrer dans cette matrice et dans la politique');
    }

    public function testEveryOtherProfileGetsTheSameUniform404OnEveryRoute(): void
    {
        $id = $this->verified(RightsRequestKind::Access);
        $forged = Uuid::v7()->toRfc4122();
        $bodies = [];
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->superAdmin, $this->organizerId], [$this->admin, $this->partnerId], [$this->admin, $forged]] as [$user, $commune]) {
            $this->as($user);
            foreach ([['GET', $this->url($commune)], ['GET', $this->url($commune, '/nouvelle')], ['POST', $this->url($commune, '/nouvelle')], ['GET', $this->url($commune, '/'.$id)]] as [$method, $path]) {
                $this->client->request($method, $path, ['_token' => 'x']);
                self::assertSame(404, $this->client->getResponse()->getStatusCode(), $method.' '.$path);
                $bodies[] = (string) $this->client->getResponse()->getContent();
            }
            foreach (['identite', 'proroger', 'decision', 'cloture', 'mention', 'effacement', 'export'] as $action) {
                $this->client->request('POST', $this->url($commune, '/'.$id.'/'.$action), ['_token' => 'x', 'decision' => 'GRANTED', 'method' => 'OTHER']);
                self::assertSame(404, $this->client->getResponse()->getStatusCode(), $action.' avant le jeton CSRF');
                $bodies[] = (string) $this->client->getResponse()->getContent();
            }
        }
        // L'administrateur d'une AUTRE commune : sa propre page est servie, mais la demande de l'organisatrice lui est inconnue (même 404).
        $this->as($this->partnerAdmin);
        foreach ([['GET', $this->url($this->partnerId, '/'.$id)], ['POST', $this->url($this->partnerId, '/'.$id.'/decision')], ['POST', $this->url($this->partnerId, '/'.$id.'/export')], ['GET', $this->url($this->partnerId, '/'.$forged)]] as [$method, $path]) {
            $this->client->request($method, $path, ['_token' => 'x', 'decision' => 'GRANTED']);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $method.' '.$path);
            $bodies[] = (string) $this->client->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'réponse uniforme : jamais « droits », « clos » ni l\'existence d\'une demande');
        self::assertSame('IDENTITY_VERIFIED', $this->requestRow($id)['status']);
    }

    public function testAnUnauthenticatedVisitorIsSentToTheLogin(): void
    {
        $this->client->request('GET', $this->url($this->organizerId));
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheNavigationLinkIsOnlyShownToTheAdministratorsAndNeverReplacesTheServerCheck(): void
    {
        $this->as($this->admin);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements');
        self::assertStringContainsString('Droits des personnes', (string) $this->client->getResponse()->getContent());
        foreach ([$this->manager, $this->instructor] as $user) {
            $this->as($user);
            $this->client->request('GET', '/communes/'.$this->organizerId.'/evenements');
            self::assertStringNotContainsString('Droits des personnes', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testTheWholeFlowThroughTheFormsOnAClosedEventShowsNothingAboutThePerson(): void
    {
        $this->close();
        $this->as($this->admin);
        $reference = $this->publicReference();

        $crawler = $this->client->request('GET', $this->url($this->organizerId, '/nouvelle'));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['kind' => 'ACCESS', 'references' => $reference]));
        self::assertResponseRedirects();
        $show = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/droits/[0-9a-f-]{36}$#', $show);
        $id = substr($show, -36);

        $crawler = $this->client->request('GET', $show);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Attester l’identité', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Décider', (string) $this->client->getResponse()->getContent(), 'pas de décision avant l\'attestation');
        $this->client->submit($crawler->selectButton('J’atteste l’identité')->form(['method' => 'DOCUMENT_CHECKED']));
        $crawler = $this->client->request('GET', $show);
        $this->client->submit($crawler->selectButton('Enregistrer la décision')->form(['decision' => 'GRANTED']));
        self::assertSame('DECIDED', $this->requestRow($id)['status']);

        foreach ([$this->url($this->organizerId), $show] as $page) {
            $this->client->request('GET', $page);
            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
            foreach (self::SENTINELS as $sentinel) {
                self::assertStringNotContainsStringIgnoringCase($sentinel, $body, 'aucune donnée de la personne affichée');
            }
        }
        $this->client->request('GET', $show);
        self::assertStringContainsString('Télécharger l’export', (string) $this->client->getResponse()->getContent());
    }

    public function testAnInvalidTokenChangesNothing(): void
    {
        $id = $this->register(RightsRequestKind::Access);
        $this->as($this->admin);

        $this->client->request('POST', $this->url($this->organizerId, '/'.$id.'/identite'), ['_token' => 'invalide', 'method' => 'DOCUMENT_CHECKED']);

        self::assertResponseRedirects();
        self::assertSame('RECEIVED', $this->requestRow($id)['status']);
    }

    public function testTwoConcurrentDecisionsCannotBothSucceed(): void
    {
        $id = $this->verified(RightsRequestKind::Access);
        $admin = $this->admin->id()->toRfc4122();

        $results = [];
        foreach (ParallelPhp::run('rights-race.php', [['decide', $admin, $this->organizerId, $id, 'GRANTED'], ['decide', $admin, $this->organizerId, $id, 'REFUSED', 'OTHER_GROUNDS'], ['decide', $admin, $this->organizerId, $id, 'GRANTED']]) as $raw) {
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            self::assertArrayNotHasKey('error', $decoded, json_encode($decoded, \JSON_THROW_ON_ERROR));
            $results[] = $decoded;
        }

        self::assertSame(1, \count(array_filter($results, static fn (array $r): bool => $r['ok'])), 'une seule décision aboutit');
        self::assertSame('DECIDED', $this->requestRow($id)['status']);
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_REQUEST_DECIDED'));
    }
}
