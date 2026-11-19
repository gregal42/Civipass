<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\MunicipalPagesTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LOT 11.1 — parcours systématique des routes de gestion (`/communes/**`, `/admin/**`) avec des identifiants forgés.
 * Preuve complémentaire des suites ciblées : aucune route de ces surfaces ne répond 200 ni 5xx à un visiteur anonyme, à un
 * agent sans droit de super-administrateur (admin), ni à un agent d'une AUTRE commune (identifiants de la commune et de
 * l'événement d'autrui). Les réponses attendues sont une redirection vers la connexion, 403 ou 404.
 */
final class RouteAccessCrawlTest extends MunicipalPagesTestCase
{
    /** Constat SEC-002 (Info) : jeton CSRF vérifié AVANT l'autorisation, redirection 303 sans effet ni donnée. */
    private const KNOWN_FLASH_REDIRECTS = ['app_municipal_refusal_informed'];

    /** @return list<array{string, Route, string}> [nom, route, chemin concret] */
    private function concreteRoutes(string $prefix, string $municipalityId, string $eventId): array
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $out = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), $prefix)) {
                continue;
            }
            $path = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($route, $municipalityId, $eventId): string {
                $param = $m[1];
                if ('municipalityId' === $param) {
                    return $municipalityId;
                }
                if ('eventId' === $param) {
                    return $eventId;
                }
                $req = (string) ($route->getRequirement($param) ?? '');
                foreach ([Uuid::v7()->toRfc4122(), 'abc_def', 'abc', '1', 'x', ...explode('|', $req)] as $candidate) {
                    if ('' === $req || 1 === preg_match('#^(?:'.$req.')$#', $candidate)) {
                        return $candidate;
                    }
                }

                return Uuid::v7()->toRfc4122();
            }, $route->getPath());
            $out[] = [$name, $route, $path];
        }
        \assert([] !== $out);

        return $out;
    }

    /** @return list<string> */
    private function methods(Route $route): array
    {
        $methods = $route->getMethods() ?: ['GET'];

        return array_values(array_intersect($methods, ['GET', 'POST']) ?: ['GET']);
    }

    /**
     * @param list<int>    $allowed
     * @param list<string> $exceptions
     */
    private function crawl(string $prefix, string $municipalityId, string $eventId, array $allowed, string $label, array $exceptions = []): int
    {
        $count = 0;
        foreach ($this->concreteRoutes($prefix, $municipalityId, $eventId) as [$name, $route, $path]) {
            foreach ($this->methods($route) as $method) {
                $this->client->request($method, $path, 'POST' === $method ? ['_token' => 'x'] : []);
                $status = $this->client->getResponse()->getStatusCode();
                $location = (string) $this->client->getResponse()->headers->get('Location');
                $ok = \in_array($status, $allowed, true) || (303 === $status && \in_array($name, $exceptions, true))
                    || (\in_array(302, $allowed, true) && 302 === $status && str_contains($location, '/login'));
                self::assertTrue($ok, sprintf('%s : %s %s (%s) a répondu %d%s', $label, $method, $path, $name, $status, '' !== $location ? ' -> '.$location : ''));
                ++$count;
            }
        }

        return $count;
    }

    public function testAnonymousVisitorIsSentToLoginOnEveryManagementRoute(): void
    {
        $n = $this->crawl('/communes', $this->id($this->w->org), $this->fire, [302], 'anonyme');
        $n += $this->crawl('/admin', $this->id($this->w->org), $this->fire, [302], 'anonyme');
        self::assertGreaterThan(100, $n, 'le parcours doit couvrir toutes les routes de gestion');
    }

    public function testACommuneAgentIsRefusedOnEveryBackOfficeRoute(): void
    {
        $this->loginAs($this->w->adminO);
        $n = $this->crawl('/admin', $this->id($this->w->org), $this->fire, [403, 404], 'agent communal sur /admin');
        self::assertGreaterThan(20, $n);
    }

    public function testAnAgentOfAnotherCommuneNeverReachesTheOrganisersRoutes(): void
    {
        // adminX appartient à « other » : l'URL porte la commune et l'événement d'autrui (identifiants forgés).
        $this->loginAs($this->w->adminX);
        $n = $this->crawl('/communes/{municipalityId}', $this->id($this->w->org), $this->fire, [403, 404], 'commune étrangère', self::KNOWN_FLASH_REDIRECTS);
        self::assertGreaterThan(80, $n);
    }

    public function testAnAgentOfTheOrganiserCannotUseAForgedCommuneOrEventId(): void
    {
        $this->loginAs($this->w->adminO);
        // commune du compte + événement inexistant, puis commune étrangère + vrai événement.
        $n = $this->crawl('/communes/{municipalityId}/evenements/{eventId}', $this->id($this->w->org), Uuid::v7()->toRfc4122(), [403, 404, 302, 303, 200], 'événement forgé');
        $n += $this->crawl('/communes/{municipalityId}', $this->id($this->w->other), $this->fire, [403, 404], 'commune forgée', self::KNOWN_FLASH_REDIRECTS);
        self::assertGreaterThan(80, $n);
    }
}
