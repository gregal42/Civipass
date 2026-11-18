<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionLifetime;
use App\Identity\Domain\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Expiration des sessions authentifiées (LOT 08.3, DM-138) : inactivité ET durée absolue, mesurées côté serveur (le navigateur ne fait qu'avertir).
 *
 * - Démarre à la connexion par mot de passe (LoginSuccessEvent) ; les visiteurs anonymes (brouillon de demande publique) ne sont JAMAIS concernés :
 *   sans jeton authentifié, ce code ne touche pas à la session.
 * - Chaque requête d'un utilisateur connecté prolonge l'inactivité, SAUF la sonde `app_session_state` (elle ne doit pas maintenir une session vivante
 *   à elle seule).
 * - À l'expiration : jeton retiré, session invalidée, message à l'écran de connexion, retour à la page demandée après reconnexion (GET uniquement,
 *   chemin interne).
 */
final readonly class SessionLifetimeListener
{
    public const STARTED = '_cp_session_started';
    public const SEEN = '_cp_session_seen';
    public const FLASH = 'session_expired';
    public const PROBE_ROUTE = 'app_session_state';

    public function __construct(
        private SessionLifetime $lifetime,
        private TokenStorageInterface $tokens,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $session = $event->getRequest()->getSession();
        $now = $this->clock->now()->getTimestamp();
        $session->set(self::STARTED, $now);
        $session->set(self::SEEN, $now);
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }
        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $started = $session->get(self::STARTED);
        $seen = $session->get(self::SEEN);
        if (!\is_int($started) || !\is_int($seen)) {
            // Session antérieure à la mise en service de la règle : la fenêtre démarre maintenant.
            $session->set(self::STARTED, $now);
            $session->set(self::SEEN, $now);

            return;
        }
        $verdict = $this->lifetime->evaluate($user, $started, $seen, $now);
        if ('ok' === $verdict['state']) {
            if (self::PROBE_ROUTE !== $request->attributes->get('_route')) {
                $session->set(self::SEEN, $now);
            }

            return;
        }

        $reason = $verdict['state'];
        $minutes = (int) round($this->lifetime->idleFor($user) / 60);
        $hours = (int) round($this->lifetime->absoluteFor($user) / 3600);
        $target = $this->returnPath($request);
        $this->tokens->setToken(null);
        $session->invalidate();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::FLASH, 'idle' === $reason
                ? sprintf('Vous avez été déconnecté après %d minutes d’inactivité. Reconnectez-vous pour continuer.', $minutes)
                : sprintf('Votre session a atteint sa durée maximale (%d h). Reconnectez-vous pour continuer.', $hours));
        }
        if (null !== $target) {
            $session->set('_security.main.target_path', $target);
        }
        if ($this->isProbeOrXhr($request)) {
            $event->setResponse(new JsonResponse(['expired' => true, 'reason' => $reason], 401, ['Cache-Control' => 'no-store']));

            return;
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }

    private function isProbeOrXhr(Request $request): bool
    {
        return self::PROBE_ROUTE === $request->attributes->get('_route') || $request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    /** Chemin interne à rouvrir après reconnexion : GET navigateur seulement, jamais une URL absolue ou protocole-relative. */
    private function returnPath(Request $request): ?string
    {
        if (!$request->isMethod('GET') || $this->isProbeOrXhr($request) || 'app_login' === $request->attributes->get('_route')) {
            return null;
        }
        $uri = $request->getRequestUri();

        return str_starts_with($uri, '/') && !str_starts_with($uri, '//') && !str_contains($uri, '\\') ? $uri : null;
    }
}
