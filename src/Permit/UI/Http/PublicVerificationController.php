<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PublicVerificationOutcome;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vérification publique d'un laissez-passer par son QR (LOT 05.4.2 et 05.4.3, DM-115) : accès par le SEUL jeton du QR, sans compte ni session (pare-feu
 * `public_verification`, `security: false`).
 *
 * Interrupteur d'exploitation `PUBLIC_VERIFICATION_ENABLED` (désactivé par défaut) : coupé, la route répond la même 404 qu'un jeton inconnu, SANS
 * appeler le lecteur, SANS toucher aux compteurs ni à la base. Ordre : interrupteur, plafond de consultations par IP (429), plafond d'échecs (429, avant
 * toute recherche), lecteur. Jeton mal formé ou inconnu : une seule réponse 404 (même corps, mêmes en-têtes) ; seul l'inconnu bien formé compte comme
 * échec. Jeton retiré : 410 sans aucune donnée, jamais un échec. Panne du stockage des compteurs : 503 explicite et journalisée, jamais une limitation
 * silencieusement abandonnée. Aucune redirection ; aucune exception du lecteur rattrapée (le jeton n'est cité nulle part : `#[\SensitiveParameter]`).
 * Les en-têtes de protection de toutes les réponses viennent de PublicVerificationHeadersListener.
 */
final class PublicVerificationController extends AbstractController
{
    public function __construct(
        private readonly PublicVerificationReader $verifications,
        private readonly VerificationThrottle $throttle,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:PUBLIC_VERIFICATION_ENABLED)%')] private readonly bool $enabled,
    ) {
    }

    // Deux chemins, départagés par une condition : sans elle le routeur REDIRIGERAIT `/v/{jeton}/` vers `/v/{jeton}` (en-tête Location, donc jeton dans
    // la réponse et les journaux). Les deux servent la même page ; aucun ne redirige.
    #[Route('/v/{token}', name: 'app_public_verification', requirements: ['token' => '[^/]{1,200}'], methods: ['GET'], condition: "not (request.getPathInfo() matches '#/$#')")]
    #[Route('/v/{token}/', name: 'app_public_verification_slash', requirements: ['token' => '[^/]{1,200}'], methods: ['GET'], condition: "request.getPathInfo() matches '#/$#'")]
    public function show(#[\SensitiveParameter] string $token, Request $request): Response
    {
        if (!$this->enabled) {
            return $this->unknown();
        }
        $client = VerificationClientKey::of($request);
        try {
            $wait = $this->throttle->check($client);
        } catch (ThrottleStorageUnavailable $e) {
            return $this->unavailable($e);
        }
        if (null !== $wait) {
            return $this->throttled($wait);
        }
        $lookup = $this->verifications->find($token, $this->clock->now());
        $response = match ($lookup->outcome) {
            PublicVerificationOutcome::Found => $this->render('permit/verification.html.twig', [
                'page_title' => 'Vérification',
                'verification' => $lookup->verification,
            ]),
            PublicVerificationOutcome::EventClosed => $this->render('permit/verification_closed.html.twig', ['page_title' => 'Vérification']),
            PublicVerificationOutcome::Replaced => $this->render('permit/verification_replaced.html.twig', ['page_title' => 'Vérification'], new Response('', Response::HTTP_GONE)),
            PublicVerificationOutcome::Unknown, PublicVerificationOutcome::Malformed => $this->unknown(),
        };
        if (PublicVerificationOutcome::Unknown === $lookup->outcome) {
            try {
                $this->throttle->recordFailure($client);
            } catch (ThrottleStorageUnavailable $e) {
                $this->logUnavailable($e);
            }
        }

        return $response;
    }

    /** Réponse UNIQUE : rien ne distingue un jeton mal formé, inconnu ou une route coupée. */
    private function unknown(): Response
    {
        return $this->render('permit/verification_unknown.html.twig', ['page_title' => 'Vérification'], new Response('', Response::HTTP_NOT_FOUND));
    }

    private function throttled(int $seconds): Response
    {
        $response = $this->render('permit/verification_throttled.html.twig', ['page_title' => 'Vérification', 'minutes' => (int) ceil($seconds / 60)], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        $response->headers->set('Retry-After', (string) $seconds);

        return $response;
    }

    private function unavailable(ThrottleStorageUnavailable $e): Response
    {
        $this->logUnavailable($e);
        $response = $this->render('permit/verification_unavailable.html.twig', ['page_title' => 'Vérification'], new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        $response->headers->set('Retry-After', '60');

        return $response;
    }

    /** Observable et sans secret : message fixe, dont seule la classe de la cause est interpolée (jamais son message, jamais la requête ni l'adresse). */
    private function logUnavailable(ThrottleStorageUnavailable $e): void
    {
        $this->logger->error('Contrôle public : compteurs de limitation indisponibles (cause : {cause}), vérification suspendue (503).', ['cause' => null === $e->getPrevious() ? 'inconnue' : $e->getPrevious()::class]);
    }
}
