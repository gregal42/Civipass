<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de protection de TOUTE réponse du contrôle public (`/v/…`, LOT 05.4.2, DM-115, DT-048) : succès, 404, 410, 405, erreur 500. Un
 * contrôleur ne voit pas les erreurs du routeur ni les exceptions ; ce listener, lui, voit toutes les réponses du chemin. Rien n'est mis en
 * cache, indexé, ni transmis en Referer ; aucune ressource tierce, aucun script, aucun cadre. Il ne retire AUCUN cookie : l'absence de cookie vient du pare-feu `public_verification` (`security: false`, aucune session), et un cookie qui apparaîtrait un jour doit faire échouer les tests au lieu d'être masqué.
 */
final class PublicVerificationHeadersListener
{
    public const CSP = "default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    /** Priorité -250 : APRÈS `ErrorListener::removeCspHeader` (-128), qui retire la CSP des pages d'erreur de débogage ; la politique doit rester sur toute réponse de `/v/`. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !self::concerns($event->getRequest()->getPathInfo())) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Content-Security-Policy', self::CSP);
    }

    public static function concerns(string $pathInfo): bool
    {
        return '/v' === $pathInfo || str_starts_with($pathInfo, '/v/');
    }
}
