<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garde-fou de l'origine dédiée au contrôle (LOT 10, DT-056) : INACTIF tant que `CONTROL_HOST` est vide (développement, tests).
 *
 * G1 — sur l'hôte de contrôle, seules les routes de la liste blanche (`ControlRouteAllowlist`) sont servies ; toute autre requête reçoit le 404
 * générique de l'application, avant le routage, la sécurité et la base de données : aucun contrôleur, aucun cookie, aucune donnée.
 */
final readonly class ControlOriginListener
{
    private string $controlHost;

    public function __construct(#[Autowire('%env(CONTROL_HOST)%')] string $controlHost)
    {
        $this->controlHost = strtolower(trim($controlHost));
    }

    /** Priorité 512 : avant le routeur (32) et le pare-feu (8). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || '' === $this->controlHost) {
            return;
        }
        $request = $event->getRequest();
        if ($request->getHost() === $this->controlHost && !ControlRouteAllowlist::allows($request->getMethod(), $request->getPathInfo())) {
            throw new NotFoundHttpException();
        }
    }

    /** Toute réponse de l'hôte de contrôle (404 génériques compris) porte la politique de protection de la PWA ; aucun cookie n'y est posé par ce garde-fou. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -260)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || '' === $this->controlHost || $event->getRequest()->getHost() !== $this->controlHost) {
            return;
        }
        if (!ControlHeadersListener::concerns($event->getRequest()->getPathInfo())) {
            ControlHeadersListener::protect($event->getResponse(), false);
        }
    }
}
