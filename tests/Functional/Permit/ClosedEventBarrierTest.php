<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Event\UI\Http\ClosedEventRouteClassifier;
use App\Event\UI\Http\RouteClosure;
use App\Tests\Support\ClosedEventScenarioTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Barrière de clôture (LOT 05.6.2, DM-120) dans le navigateur simulé : tout accès OPÉRATIONNEL à un dossier d'un événement clos répond 410 neutre, pour tous
 * les rôles et toutes les méthodes, sans la moindre donnée ; aucune fuite d'existence pour un utilisateur non habilité (mêmes 404 qu'un dossier inexistant) ;
 * un événement actif n'est pas touché. La matrice parcourt TOUTES les routes gardées du routeur : une route ajoutée plus tard est testée sans modifier ce fichier.
 */
#[Group('requires-request-schema')]
final class ClosedEventBarrierTest extends ClosedEventScenarioTestCase
{
    /** @return list<array{string, string}> routes gardées du routeur, avec leurs méthodes (GET si aucune restriction) */
    private function guardedRoutes(): array
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $classifier = new ClosedEventRouteClassifier();
        $found = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (RouteClosure::Guarded !== $classifier->classify($route->getPath())) {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $found[] = [$name, $method];
            }
        }

        return $found;
    }

    private function path(string $routeName, ?string $municipalityId = null, ?string $requestId = null): string
    {
        $route = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($routeName) ?? self::fail('Route inconnue.');
        $values = [
            'municipalityId' => $municipalityId ?? $this->organizerId,
            'requestId' => $requestId ?? $this->requestId,
            'attachmentId' => $this->attachmentId,
            'linkId' => $this->linkId,
        ];
        $path = $route->getPath();
        foreach ($values as $key => $value) {
            $path = str_replace('{'.$key.'}', $value, $path);
        }
        self::assertStringNotContainsString('{', $path, sprintf('Paramètre non renseigné pour %s : compléter le test de matrice.', $routeName));

        return $path;
    }

    private function assertNeutral410(): void
    {
        self::assertSame(410, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $body, 'aucune donnée de dossier dans la réponse 410');
        }
        foreach ([$this->requestId, $this->attachmentId, $this->linkId] as $identifier) {
            self::assertStringNotContainsString($identifier, $body);
        }
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    // ------------------------------------------------------------------ matrice

    public function testEveryRouteOfTheApplicationIsClassified(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $classifier = new ClosedEventRouteClassifier();
        $unclassified = [];
        $publicWithResource = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            $class = $classifier->classify($path);
            if (RouteClosure::Unclassified === $class) {
                $unclassified[] = $name.' '.$path;
            }
            if (RouteClosure::Public === $class && [] !== array_filter(ClosedEventRouteClassifier::RESOURCE_PLACEHOLDERS, static fn (string $p): bool => str_contains($path, $p))) {
                $publicWithResource[$name] = (string) $classifier->justification($path);
            }
        }

        self::assertSame([], $unclassified, 'Toute route doit être classée (gardée, exemptée justifiée, réservée ou publique) : classer les nouvelles routes dans ClosedEventRouteClassifier.');
        foreach ($publicWithResource as $name => $why) {
            self::assertNotSame('', $why, $name);
        }
        self::assertContains('app_beneficiary_pass', array_keys($publicWithResource), 'le lien bénéficiaire est une route publique justifiée, fermée par BeneficiaryPassReader');
    }

    public function testTheGuardedRoutesCoverTheWholeOperationalSurface(): void
    {
        $names = array_unique(array_map(static fn (array $r): string => $r[0], $this->guardedRoutes()));

        foreach (['app_municipal_request', 'app_municipal_request_attachment', 'app_municipal_request_approve', 'app_municipal_request_refuse', 'app_municipal_request_identity', 'app_municipal_request_issued', 'app_municipal_permit_rights', 'app_municipal_permit_revoke', 'app_municipal_permit_zones', 'app_municipal_permit_zone_labels', 'app_municipal_permit_vehicles', 'app_municipal_permit_period_extend', 'app_municipal_permit_period_shorten', 'app_municipal_permit_qr', 'app_municipal_permit_access', 'app_municipal_permit_access_issue', 'app_municipal_permit_access_notify', 'app_municipal_permit_access_revoke'] as $expected) {
            self::assertContains($expected, $names, $expected.' doit être une route gardée');
        }
    }

    // ------------------------------------------------------------------ événement actif : non-régression

    public function testAnActiveEventStillServesItsOperationalRoutes(): void
    {
        $this->as($this->instructor);

        foreach (['app_municipal_request', 'app_municipal_request_attachment', 'app_municipal_permit_rights', 'app_municipal_permit_access', 'app_municipal_permit_qr'] as $name) {
            $this->client->request('GET', $this->path($name));
            self::assertNotContains($this->client->getResponse()->getStatusCode(), [404, 410], $name.' sur événement actif : ni inaccessible ni clos');
        }
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsStringIgnoringCase('Martin', (string) $this->client->getResponse()->getContent(), 'la liste d\'un événement actif est servie');
    }

    // ------------------------------------------------------------------ événement clos

    public function testEveryGuardedRouteAnswers410ForEveryRoleAndMethodOnAClosedEvent(): void
    {
        $this->close();
        $actors = [[$this->instructor, $this->organizerId], [$this->manager, $this->organizerId], [$this->admin, $this->organizerId], [$this->partnerInstructor, $this->partnerId]];
        $routes = $this->guardedRoutes();
        self::assertGreaterThan(15, \count($routes));

        foreach ($actors as [$user, $commune]) {
            $this->as($user);
            foreach ($routes as [$name, $method]) {
                $this->client->request($method, $this->path($name, $commune));
                $this->assertNeutral410();
                if ('GET' === $method) {
                    $this->client->request('HEAD', $this->path($name, $commune));
                    self::assertSame(410, $this->client->getResponse()->getStatusCode(), $name.' HEAD');
                }
            }
        }
    }

    public function testTheCommunalListNoLongerServesAClosedEventAndNeverSelectsItByDefault(): void
    {
        $this->close();
        $this->as($this->instructor);

        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $body);
        }
        self::assertStringNotContainsString($this->requestId, $body);
        self::assertStringContainsString('Aucun événement actif n’est disponible', $body, 'état vide explicite quand seuls des événements clos existent');
        self::assertStringNotContainsStringIgnoringCase('archive', $body, 'aucun lien vers le futur module d\'archives');

        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes?evenement='.$this->event->id()->toRfc4122());
        $this->assertNeutral410();
        $this->client->request('GET', '/communes/'.$this->organizerId.'/demandes?evenement='.$this->event->id()->toRfc4122().'&q=Martin');
        $this->assertNeutral410();
    }

    public function testTheIdentitySearchNoLongerFindsADossierOfAClosedEvent(): void
    {
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');
        $this->as($this->instructor);
        $this->client->request('GET', '/communes/'.$this->organizerId.'/corrections-identite?reference='.$reference);
        self::assertStringContainsString($reference, (string) $this->client->getResponse()->getContent(), 'événement actif : la recherche trouve le dossier');

        $this->close();
        $this->client->request('GET', '/communes/'.$this->organizerId.'/corrections-identite?reference='.$reference);

        $this->assertNeutral410();
        self::assertStringNotContainsString($reference, (string) $this->client->getResponse()->getContent());
    }


    // ------------------------------------------------------------------ aucune fuite d'existence

    public function testAnUnauthorisedUserNeverLearnsThatADossierExistsOrIsClosed(): void
    {
        $this->close();
        $nonexistent = Uuid::v7()->toRfc4122();
        $this->as($this->outsider);
        $answers = [];
        foreach ([
            [$this->outsiderCommuneId, $this->requestId], // sa commune, dossier d'autrui
            [$this->organizerId, $this->requestId], // commune d'autrui
            [$this->outsiderCommuneId, $nonexistent], // dossier inexistant
            [$this->organizerId, $nonexistent],
            [Uuid::v7()->toRfc4122(), $this->requestId], // commune inexistante
        ] as [$commune, $request]) {
            foreach (['app_municipal_request', 'app_municipal_request_attachment', 'app_municipal_permit_rights', 'app_municipal_permit_access'] as $name) {
                $this->client->request('GET', $this->path($name, $commune, $request));
                $answers[$name][] = [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
            }
        }

        foreach ($answers as $name => $variants) {
            foreach ($variants as [$status, $body]) {
                self::assertSame(404, $status, $name);
                self::assertSame($variants[0][1], $body, $name.' : réponse uniforme, jamais « clos »');
            }
        }
    }

    public function testAnUnauthenticatedVisitorIsRedirectedToTheLoginNotTold410(): void
    {
        $this->close();

        $this->client->request('GET', $this->path('app_municipal_request'));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ------------------------------------------------------------------ chemins de cycle de vie séparés

    public function testTheLifecyclePathsAreNeverBehindTheBarrier(): void
    {
        $classifier = new ClosedEventRouteClassifier();

        self::assertSame(RouteClosure::Reserved, $classifier->classify('/communes/{municipalityId}/archives/{archiveId}'));
        self::assertSame(RouteClosure::Reserved, $classifier->classify('/communes/{municipalityId}/retention/purge'));
        self::assertSame(RouteClosure::Exempt, $classifier->classify('/communes/{municipalityId}/evenements/{eventId}'));
        self::assertSame(RouteClosure::Guarded, $classifier->classify('/communes/{municipalityId}/demandes/{requestId}/droits/qr'));
        self::assertSame(RouteClosure::BusinessGuarded, $classifier->classify('/communes/{municipalityId}/demandes'));
        self::assertSame(RouteClosure::Unclassified, $classifier->classify('/communes/{municipalityId}/nouveau-module/{requestId}'), 'une famille inconnue n\'est jamais tolérée');
        self::assertSame(RouteClosure::Unclassified, $classifier->classify('/api/demandes/{requestId}'));
    }
}
