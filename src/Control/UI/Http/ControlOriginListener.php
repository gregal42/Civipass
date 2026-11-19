<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garde-fou de l'origine dédiée au contrôle (LOT 10, DT-056) : INACTIF tant que `CONTROL_HOST` est vide (développement, tests) ; en production (`APP_ENV=prod`), si le contrôle hors connexion est activé alors que `CONTROL_HOST` est vide, les chemins `/controle…` répondent le même 404 générique (échec fermé, SEC-013).
 *
 * G1 — sur l'hôte de contrôle, seules les routes de la liste blanche (`ControlRouteAllowlist`) sont servies ; toute autre requête reçoit le 404
 * générique de l'application, avant le routage, la sécurité et la base de données : aucun contrôleur, aucun cookie, aucune donnée.
 *
 * G3 — sur tout AUTRE hôte (l'origine de gestion), les chemins `/controle…` répondent le même 404 générique : la PWA, son Service Worker et son stockage local
 * ne peuvent jamais s'installer sur l'origine de gestion, où vivent les cookies de session des agents.
 */
final readonly class ControlOriginListener
{
    private string $controlHost;

    /** Échec fermé (SEC-013) : en production, le contrôle hors connexion activé SANS `CONTROL_HOST` ne partage jamais l'origine des cookies de session. */
    private bool $failClosed;

    public function __construct(
        #[Autowire('%env(CONTROL_HOST)%')] string $controlHost,
        #[Autowire('%env(bool:OFFLINE_CONTROL_ENABLED)%')] bool $offlineControlEnabled = false,
        #[Autowire('%kernel.environment%')] string $environment = 'dev',
    ) {
        $this->controlHost = strtolower(trim($controlHost));
        $this->failClosed = $offlineControlEnabled && 'prod' === $environment;
    }

    /** Priorité 512 : avant le routeur (32) et le pare-feu (8). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ('' === $this->controlHost) {
            if ($this->failClosed && ControlHeadersListener::concerns($path)) {
                throw new NotFoundHttpException(); // SEC-013 : pas d'origine dédiée, pas de PWA en production
            }

            return;
        }
        if (self::hostOf($request) === $this->controlHost) {
            if (!ControlRouteAllowlist::allows($request->getMethod(), $path)) {
                throw new NotFoundHttpException(); // G1
            }

            return;
        }
        if (ControlHeadersListener::concerns($path)) {
            throw new NotFoundHttpException(); // G3
        }
    }

    /** Toute réponse de l'hôte de contrôle (404 génériques compris) porte la politique de protection de la PWA ; aucun cookie n'y est posé par ce garde-fou. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -260)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || '' === $this->controlHost || self::hostOf($event->getRequest()) !== $this->controlHost) {
            return;
        }
        if (!ControlHeadersListener::concerns($event->getRequest()->getPathInfo())) {
            ControlHeadersListener::protect($event->getResponse(), false);
        }
    }

    /**
     * Nom d'hôte de la requête (en-tête `Host`, minuscules, sans port ni point final), utilisé UNIQUEMENT pour comparer à `CONTROL_HOST` : jamais pour
     * construire une URL (règle d'architecture : DEFAULT_URI est la seule source des adresses absolues).
     */
    private static function hostOf(Request $request): string
    {
        $host = strtolower(trim((string) $request->headers->get('Host')));

        return rtrim((string) preg_replace('/:\d+$/', '', $host), '.');
    }
}
