<?php

declare(strict_types=1);

namespace App\Identity\UI;

use App\Identity\Application\InvalidPasswordResetToken;
use App\Identity\Application\PasswordResetService;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Parcours « mot de passe oublié », sans JavaScript (formulaires HTML, jetons CSRF de session).
 * Le jeton de réinitialisation quitte l'URL dès son contrôle (session), pour ne pas fuiter par l'en-tête Referer.
 */
final class PasswordResetController extends AbstractController
{
    private const SESSION_KEY = 'password_reset_token';

    public function __construct(
        private readonly PasswordResetService $service,
        #[Autowire(service: 'limiter.password_reset_email')]
        private readonly RateLimiterFactory $emailLimiter,
        #[Autowire(service: 'limiter.password_reset_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.password_reset_token_ip')]
        private readonly RateLimiterFactory $tokenIpLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->page('security/password_reset/forgot.html.twig');
        }
        if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_csrf_token'))) {
            return $this->page('security/password_reset/forgot.html.twig', ['error' => 'Jeton de sécurité invalide : rechargez la page et réessayez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = (string) $request->request->get('email', '');
        // Les DEUX compteurs sont toujours consommés (pas de court-circuit) ; au-delà des limites, l'envoi est
        // abandonné en silence et la réponse reste identique : rien ne distingue inconnu, désactivé ou limité.
        $ipAccepted = $this->ipLimiter->create($this->hash($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
        $emailAccepted = $this->emailLimiter->create($this->hash(mb_strtolower(trim($email))))->consume()->isAccepted();
        if ($ipAccepted && $emailAccepted) {
            $this->service->requestReset($email);
        }

        return $this->redirectToRoute('app_forgot_password_check_email');
    }

    #[Route('/forgot-password/check-email', name: 'app_forgot_password_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->page('security/password_reset/check_email.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password_token', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function token(string $token, Request $request): Response
    {
        if (!$this->tokenAttemptAccepted($request)) {
            return $this->page('security/password_reset/too_many.html.twig', [], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$this->service->isTokenUsable($token)) {
            return $this->page('security/password_reset/invalid.html.twig');
        }

        // Le jeton est retiré de l'URL (session) avant tout affichage de formulaire.
        $request->getSession()->set(self::SESSION_KEY, $token);

        return $this->redirectToRoute('app_reset_password');
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request): Response
    {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_KEY);
        if (!\is_string($token)) {
            return $this->redirectToRoute('app_forgot_password');
        }
        if (!$this->tokenAttemptAccepted($request)) {
            return $this->page('security/password_reset/too_many.html.twig', [], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$request->isMethod('POST')) {
            if (!$this->service->isTokenUsable($token)) {
                $session->remove(self::SESSION_KEY);

                return $this->page('security/password_reset/invalid.html.twig');
            }

            return $this->page('security/password_reset/reset.html.twig');
        }

        if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_csrf_token'))) {
            return $this->page('security/password_reset/reset.html.twig', ['error' => 'Jeton de sécurité invalide : rechargez la page et réessayez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $password = (string) $request->request->get('password', '');
        if (!hash_equals($password, (string) $request->request->get('password_confirm', ''))) {
            return $this->page('security/password_reset/reset.html.twig', ['error' => 'Les deux mots de passe ne correspondent pas.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->service->resetPassword($token, $password);
        } catch (InvalidPasswordResetToken) {
            $session->remove(self::SESSION_KEY);

            return $this->page('security/password_reset/invalid.html.twig');
        } catch (DomainRuleViolation $violation) {
            // Mot de passe refusé par la politique : le jeton n'est pas consommé, l'utilisateur peut réessayer.
            return $this->page('security/password_reset/reset.html.twig', ['error' => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Nouvelle session : rien de l'ancienne ne subsiste (les autres sessions sont invalidées par le changement de hash).
        $session->invalidate();

        return $this->redirectToRoute('app_reset_password_done');
    }

    #[Route('/reset-password/done', name: 'app_reset_password_done', methods: ['GET'])]
    public function done(): Response
    {
        return $this->page('security/password_reset/done.html.twig');
    }

    private function tokenAttemptAccepted(Request $request): bool
    {
        return $this->tokenIpLimiter->create($this->hash($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
    }

    /**
     * Clé de limiteur : empreinte HMAC (aucune adresse email ni IP en clair dans le cache).
     */
    private function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->secret), 0, 32);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $context = [], int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $context, new Response('', $status));
        // Pages portant un jeton CSRF ou liées à un jeton de réinitialisation : ni cache, ni Referer, ni indexation.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
