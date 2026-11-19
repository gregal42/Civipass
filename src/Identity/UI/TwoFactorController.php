<?php

declare(strict_types=1);

namespace App\Identity\UI;

use App\Identity\Application\TwoFactorService;
use App\Identity\Application\TwoFactorState;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\TwoFactorGate;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Shared\UI\Http\ClientNetworkKey;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription et vérification du second facteur TOTP des comptes CWS (SEC-006). Réservé à un super-administrateur authentifié ; tout autre compte reçoit un 404
 * (ces pages n'existent pas pour lui). Essais limités par compte et par adresse (stockage strict). Aucune page n'est mise en cache ; les codes de secours ne
 * sont affichés qu'une seule fois, dans la réponse de la confirmation.
 */
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        #[Autowire(service: 'limiter.two_factor_user')] private readonly RateLimiterFactoryInterface $userLimiter,
        #[Autowire(service: 'limiter.two_factor_ip')] private readonly RateLimiterFactoryInterface $ipLimiter,
    ) {
    }

    #[Route('/2fa/activation', name: 'app_two_factor_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request): Response
    {
        $user = $this->eligibleUser();
        if (TwoFactorState::Active === $this->twoFactor->state($user)) {
            return $this->redirectToRoute($request->getSession()->get(TwoFactorGate::SESSION_KEY) ? 'app_login' : 'app_two_factor_challenge');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf('two_factor_enroll', $request);
            if (null !== ($wait = $this->throttle($user, $request))) {
                return $this->tooMany($wait);
            }
            try {
                $codes = $this->twoFactor->confirmEnrollment($user, (string) $request->request->get('code', ''));
                $this->markPassed($request);

                return $this->page('security/two_factor/codes.html.twig', ['codes' => $codes]);
            } catch (OperationNotAllowed) {
                return $this->redirectToRoute('app_login'); // aucune inscription en cours (ou déjà active) : retour à l'accueil de connexion
            } catch (DomainRuleViolation) {
                $error = 'Code incorrect. Vérifiez l\'heure de votre téléphone et saisissez le code affiché maintenant.';
            }
        }
        $challenge = $this->twoFactor->enrollmentChallenge($user);
        $qr = (new Builder(writer: new SvgWriter(), writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true], data: $challenge->uri, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 220, margin: 12, roundBlockSizeMode: RoundBlockSizeMode::Margin))->build()->getString();

        return $this->page('security/two_factor/enroll.html.twig', ['secret' => $challenge->grouped(), 'qr' => $qr, 'error' => $error], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/2fa/verification', name: 'app_two_factor_challenge', methods: ['GET', 'POST'])]
    public function challenge(Request $request): Response
    {
        $user = $this->eligibleUser();
        if (TwoFactorState::Active !== $this->twoFactor->state($user)) {
            return $this->redirectToRoute('app_two_factor_enroll');
        }
        if (true === $request->getSession()->get(TwoFactorGate::SESSION_KEY)) {
            return $this->redirectToRoute('app_login');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf('two_factor_challenge', $request);
            if (null !== ($wait = $this->throttle($user, $request))) {
                return $this->tooMany($wait);
            }
            if ($this->twoFactor->verify($user, (string) $request->request->get('code', ''))->isAccepted()) {
                $this->markPassed($request);

                return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
            }
            $error = 'Code incorrect ou déjà utilisé.';
        }

        return $this->page('security/two_factor/challenge.html.twig', ['error' => $error], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function eligibleUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->twoFactor->isRequiredFor($user)) {
            throw $this->createNotFoundException(); // ces pages n'existent pas pour un autre compte
        }

        return $user;
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    /** @return int|null secondes à attendre, ou null si l'essai est admis */
    private function throttle(User $user, Request $request): ?int
    {
        foreach ([$this->userLimiter->create($user->id()->toRfc4122())->consume(), $this->ipLimiter->create(ClientNetworkKey::of($request))->consume()] as $limit) {
            if (!$limit->isAccepted()) {
                return max(1, $limit->getRetryAfter()->getTimestamp() - time());
            }
        }

        return null;
    }

    private function tooMany(int $wait): Response
    {
        $response = $this->page('security/two_factor/challenge.html.twig', ['error' => 'Trop d\'essais. Patientez quelques minutes avant de réessayer.'], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) min($wait, 900));

        return $response;
    }

    private function markPassed(Request $request): void
    {
        $session = $request->getSession();
        $session->set(TwoFactorGate::SESSION_KEY, true);
        $session->migrate(true); // changement de niveau d'authentification : nouvel identifiant de session
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $parameters, new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private'); // secret, codes de secours, jeton CSRF : jamais en cache

        return $response;
    }
}
