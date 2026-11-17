<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de protection de TOUTE réponse de `/controle/*` (LOT 09, DT-056) : PWA, Service Worker, manifeste et API d'appareil, y compris 404, 429, 5xx.
 * Aucun script ni ressource tiers (CSP `script-src 'self'`, pas de `unsafe-inline` ni `unsafe-eval`, pas de WebAssembly), aucune indexation, aucun Referer.
 * Seule la caméra de l'origine est autorisée. L'API n'est jamais mise en cache ; le gabarit de la PWA et le Service Worker se revalident
 * (`no-cache`) : le Service Worker, lui, conserve la coquille pour le mode hors ligne. Tout assouplissement de cette politique exige une nouvelle validation.
 */
final class ControlHeadersListener
{
    public const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    /** Priorité -250 : après le retrait de la CSP des pages d'erreur de débogage (comme le contrôle public). */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !self::concerns($event->getRequest()->getPathInfo())) {
            return;
        }
        $response = $event->getResponse();
        $headers = $response->headers;
        $api = str_starts_with($event->getRequest()->getPathInfo(), '/controle/api/');
        $headers->set('Cache-Control', $api || $response->isClientError() || $response->isServerError() ? 'no-store, private' : 'no-cache, private');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Content-Security-Policy', self::CSP);
    }

    public static function concerns(string $pathInfo): bool
    {
        return '/controle' === $pathInfo || str_starts_with($pathInfo, '/controle/');
    }
}
