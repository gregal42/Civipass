<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Ajoute l'avertissement d'expiration (LOT 08.3) à la fin des pages de l'espace municipal, sans modifier le gabarit importé du design system (dont l'empreinte est
 * contrôlée). Uniquement : requête principale, réponse HTML 200 portant la coquille `cp-app`, utilisateur authentifié (le gabarit ne rend rien sinon).
 */
final readonly class SessionGuardInjector
{
    public function __construct(private Environment $twig)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || 200 !== $response->getStatusCode() || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }
        $content = (string) $response->getContent();
        if (!str_contains($content, 'class="cp-app"') || false === ($position = strripos($content, '</body>'))) {
            return;
        }
        $guard = $this->twig->render('components/session_guard.html.twig');
        if ('' === trim($guard)) {
            return;
        }
        $response->setContent(substr($content, 0, $position).$guard.substr($content, $position));
    }
}
