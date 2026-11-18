<?php

declare(strict_types=1);

namespace App\Identity\UI\Twig;

use App\Identity\Application\SessionLifetime;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SessionLifetimeListener;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

/** Données de l'avertissement d'expiration (LOT 08.3) : secondes restantes selon le serveur, ou null hors session authentifiée. */
final readonly class SessionGuardExtension
{
    public function __construct(private SessionLifetime $lifetime, private TokenStorageInterface $tokens, private RequestStack $requests, private ClockInterface $clock)
    {
    }

    /** @return array{remaining: int, warning: int}|null */
    #[AsTwigFunction('session_guard')]
    public function guard(): ?array
    {
        $user = $this->tokens->getToken()?->getUser();
        $request = $this->requests->getCurrentRequest();
        if (!$user instanceof User || null === $request || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        $started = $session->get(SessionLifetimeListener::STARTED);
        $seen = $session->get(SessionLifetimeListener::SEEN);
        if (!\is_int($started) || !\is_int($seen)) {
            return null;
        }
        $verdict = $this->lifetime->evaluate($user, $started, $seen, $this->clock->now()->getTimestamp());

        return ['remaining' => max(0, $verdict['remaining']), 'warning' => $this->lifetime->warning];
    }
}
