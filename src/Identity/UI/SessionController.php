<?php

declare(strict_types=1);

namespace App\Identity\UI;

use App\Identity\Application\SessionLifetime;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SessionLifetimeListener;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde et prolongation de la session authentifiée (LOT 08.3). L'avertissement du navigateur n'est qu'un confort : l'expiration est décidée côté serveur
 * par SessionLifetimeListener. La sonde ne prolonge JAMAIS la session ; seule la prolongation (POST + jeton CSRF) le fait, et jamais au-delà de la durée absolue.
 */
final class SessionController extends AbstractController
{
    public function __construct(private readonly SessionLifetime $lifetime, private readonly ClockInterface $clock)
    {
    }

    #[Route('/session/etat', name: SessionLifetimeListener::PROBE_ROUTE, methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    #[Route('/session/prolonger', name: 'app_session_extend', methods: ['POST'])]
    public function extend(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('session_extend', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'csrf'], 403, ['Cache-Control' => 'no-store']);
        }

        // Le listener a déjà prolongé l'inactivité pour cette requête (route différente de la sonde) ; on ne fait que décrire l'état.
        return $this->describe($request);
    }

    private function describe(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $session = $request->getSession();
        if (!$user instanceof User) {
            return new JsonResponse(['expired' => true], 401, ['Cache-Control' => 'no-store']);
        }
        $verdict = $this->lifetime->evaluate($user, (int) $session->get(SessionLifetimeListener::STARTED), (int) $session->get(SessionLifetimeListener::SEEN), $this->clock->now()->getTimestamp());

        return new JsonResponse([
            'remaining' => max(0, $verdict['remaining']),
            'extendable' => $verdict['absoluteRemaining'] > $this->lifetime->idleFor($user),
            'absoluteRemaining' => max(0, $verdict['absoluteRemaining']),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
