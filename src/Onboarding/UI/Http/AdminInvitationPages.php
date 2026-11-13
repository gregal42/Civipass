<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Http;

use App\Identity\Domain\User;
use App\Onboarding\Application\AdminInvitationEnrollment;
use App\Onboarding\Application\AdminInvitationResolution;
use App\Onboarding\Application\InvitationDispatcher;
use App\Onboarding\Domain\LinkStatus;
use App\Onboarding\Domain\SignupData;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Pages PUBLIQUES de l'invitation d'administrateur communal émise par CWS (LOT 07.1), servies par les routes EXISTANTES `/invitation/{jeton}`,
 * `/invitation` et `/invitation/rejoindre` (InvitationLinkController délègue ici) : le masquage des jetons dans les journaux nginx, la liste blanche
 * des routes fixes et leurs tests restent inchangés. Le lien n'accorde AUCUN droit : il ouvre une
 * page d'accueil puis un parcours (création du compte ou connexion avec l'adresse invitée, attestation d'habilitation). Le jeton
 * quitte l'URL dès son contrôle (session) ; pages non mises en cache, sans référent, non indexées ; limitation de débit ; liens
 * invalides, expirés, utilisés ou remplacés distingués sans rien révéler d'autre ; jamais de jeton dans les journaux.
 */
final class AdminInvitationPages extends AbstractController
{
    public const SESSION_KEY = 'onboarding.admin_invitation_token';

    public function __construct(
        private readonly AdminInvitationEnrollment $enrollment,
        private readonly Security $security,
        #[Autowire(service: 'limiter.invitation_enroll_ip')]
        private readonly RateLimiterFactory $enrollIpLimiter,
    ) {
    }

    public function open(#[\SensitiveParameter] string $token, Request $request): Response
    {
        // Limitation par IP déjà appliquée par InvitationLinkController (qui délègue ici).
        $resolution = $this->enrollment->resolve($token);
        if (!$resolution->isUsable()) {
            return $this->unusable($resolution);
        }
        $request->getSession()->set(self::SESSION_KEY, $token);

        return $this->secured(new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->generateUrl('app_invitation')]));
    }

    public function landing(Request $request): Response
    {
        $resolution = $this->current($request);
        if (!$resolution->isUsable()) {
            $request->getSession()->remove(self::SESSION_KEY);

            return $this->unusable($resolution);
        }

        return $this->page($request, $resolution);
    }

    public function accept(Request $request): Response
    {
        $resolution = $this->current($request);
        if (!$resolution->isUsable()) {
            return $this->unusable($resolution);
        }
        $post = $request->request->all();
        if (!$this->isCsrfTokenValid('admin_invitation_accept', $this->text($post['_token'] ?? ''))) {
            return $this->page($request, $resolution, ['error' => 'Le formulaire a expiré. Validez à nouveau.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->enrollIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooMany();
        }
        $current = $this->getUser();
        $current = $current instanceof User ? $current : null;
        $form = ['first_name' => $this->text($post['first_name'] ?? ''), 'last_name' => $this->text($post['last_name'] ?? '')];
        $signup = null;
        if (null === $current) {
            $password = $this->text($post['password'] ?? '');
            if ($password !== $this->text($post['password_confirm'] ?? '')) {
                return $this->page($request, $resolution, ['error' => 'Les deux mots de passe ne sont pas identiques.', 'form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $signup = new SignupData($form['first_name'], $form['last_name'], $password);
        }

        try {
            [$user, $accountCreated, $municipalityId] = $this->enrollment->accept($resolution, $current, $signup, '1' === $this->text($post['attest'] ?? ''));
        } catch (OperationNotAllowed) {
            return $this->page($request, $this->current($request), [], Response::HTTP_CONFLICT);
        } catch (DomainRuleViolation $e) {
            return $this->page($request, $resolution, ['error' => $e->getMessage(), 'form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->page($request, $resolution, ['error' => 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez.', 'form' => $form], Response::HTTP_CONFLICT);
        }
        if ($accountCreated) {
            $this->security->login($user, 'form_login', 'main'); // compte créé à l'instant : connexion directe
        }
        $request->getSession()->remove(self::SESSION_KEY);
        $this->addFlash('success', 'Vous êtes administrateur de la commune. Vous pouvez inviter vos collaborateurs et préparer vos événements.');

        return $this->redirectToRoute('app_municipal_events', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER);
    }

    private function current(Request $request): AdminInvitationResolution
    {
        $token = $request->getSession()->get(self::SESSION_KEY);

        return $this->enrollment->resolve(\is_string($token) ? $token : '');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(Request $request, AdminInvitationResolution $resolution, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        if (!$resolution->isUsable() || null === $resolution->invitation) {
            return $this->unusable($resolution);
        }
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $step = strtolower($this->enrollment->stepFor($resolution, $user)->value);
        if ('login_required' === $step) {
            $request->getSession()->set('_security.main.target_path', $this->generateUrl('app_invitation'));
        }

        return $this->secured($this->render('onboarding/admin_invitation.html.twig', $extra + [
            'case' => 'admin_'.$step,
            'commune_name' => $resolution->invitation->municipality()->name(),
            'error' => null,
            'form' => ['first_name' => '', 'last_name' => ''],
            'invited_email_masked' => InvitationDispatcher::mask($resolution->invitation->recipientEmail()),
        ], new Response('', $status)));
    }

    private function unusable(AdminInvitationResolution $resolution): Response
    {
        $status = LinkStatus::Unknown === $resolution->status ? Response::HTTP_NOT_FOUND : Response::HTTP_GONE;

        return $this->secured($this->render('onboarding/unusable.html.twig', ['status' => $resolution->status->value, 'kind' => 'admin'], new Response('', $status)));
    }

    private function tooMany(): Response
    {
        return $this->secured($this->render('onboarding/unusable.html.twig', ['status' => 'TOO_MANY', 'kind' => 'admin'], new Response('', Response::HTTP_TOO_MANY_REQUESTS)));
    }

    private function secured(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
