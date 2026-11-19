<?php

declare(strict_types=1);

namespace App\Shell;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité de base de l'origine de GESTION (SEC-002, LOT 11.1) : connexion, espace communal, Sonata, dépôt public. Ils s'appliquent à toute réponse
 * principale QUI NE PORTE PAS DÉJÀ l'en-tête : les politiques plus strictes du contrôle public (`/v/…`), du lien bénéficiaire et du contrôle hors connexion
 * (`/controle/…`) restent seules maîtresses de leurs réponses (ce listener passe après elles).
 *
 * Volontairement minimaux, sans effet sur le rendu : aucune restriction de `script-src` ni de `style-src` (Sonata, Stimulus, Turbo) tant qu'une recette en
 * navigateur réel n'a pas validé une politique complète (dette P2 du registre de sécurité). HSTS reste posé par nginx (TLS).
 */
final class ManagementSecurityHeadersListener
{
    /** Anti-clic piégé, pas de plugin, pas de `<base>` détourné. */
    public const CSP = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

    /** Priorité -300 : après les listeners de réponse du contrôle (-250, -260), qui posent leurs propres valeurs. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -300)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => self::CSP,
        ] as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
