<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\TwoFactorService;
use App\Identity\Application\TwoFactorState;
use App\Identity\Domain\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Barrière du second facteur (SEC-006, LOT 11) : un super-administrateur CWS authentifié par mot de passe n'accède à RIEN d'autre que l'inscription, la
 * vérification et la déconnexion tant que sa session n'a pas passé le second facteur. S'applique à toutes les routes (Sonata, espace communal, actions
 * `/admin/cws/…`), côté serveur. Le drapeau vit dans la session : une nouvelle session (connexion, expiration) exige un nouveau code.
 */
final readonly class TwoFactorGate
{
    public const SESSION_KEY = 'two_factor.passed';
    private const ALLOWED_ROUTES = ['app_two_factor_enroll', 'app_two_factor_challenge', 'app_logout'];

    public function __construct(
        private TokenStorageInterface $tokens,
        private TwoFactorService $twoFactor,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** Priorité 6 : après le pare-feu (8), qui a chargé l'utilisateur, et après le routeur (32), qui a fixé la route. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User || !$this->twoFactor->isRequiredFor($user)) {
            return;
        }
        $request = $event->getRequest();
        if ($request->hasSession() && true === $request->getSession()->get(self::SESSION_KEY)) {
            return;
        }
        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }
        $route = TwoFactorState::Active === $this->twoFactor->state($user) ? 'app_two_factor_challenge' : 'app_two_factor_enroll';
        $event->setResponse(new RedirectResponse($this->urls->generate($route)));
    }
}
