<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Http;

use App\Identity\Domain\User;
use App\Onboarding\Application\AdminInvitationEnrollment;
use App\Onboarding\Application\AdministratorDelegation;
use App\Onboarding\Application\AdministratorEnrollment;
use App\Onboarding\Application\InvitationDispatcher;
use App\Onboarding\Application\InvitationLinkResolver;
use App\Onboarding\Domain\InvitationLinkKind;
use App\Onboarding\Domain\LinkResolution;
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
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages PUBLIQUES des liens d'invitation intercommunale. Un lien n'accorde JAMAIS de droits : il ouvre une page de présentation
 * (contenu de l'e-mail seulement), puis un parcours qui vérifie les droits réels.
 *
 *  - Lien de CONTACT : si la commune a déjà un administrateur Civipass, connexion puis acceptation par le parcours existant ; sinon
 *    le destinataire indique l'e-mail de la personne qui sera administrateur communal (délégation).
 *  - Lien PERSONNEL : création du compte ou connexion avec l'adresse invitée, attestation de représentation, inscription comme
 *    administrateur (commune sans administrateur uniquement), puis l'acceptation de l'invitation reste une action explicite.
 *
 * Sécurité : le jeton quitte l'URL dès son contrôle (session) ; pages non mises en cache, sans référent, non indexées ; limitation de
 * débit ; liens invalides, expirés, utilisés ou remplacés distingués sans rien révéler de l'événement ; jamais de jeton dans les
 * journaux (aucune exception pour un lien invalide : réponse rendue directement).
 */
final class InvitationLinkController extends AbstractController
{
    private const SESSION_KEY = 'onboarding.invitation_token';

    public function __construct(
        private readonly InvitationLinkResolver $resolver,
        private readonly AdministratorDelegation $delegation,
        private readonly AdministratorEnrollment $enrollment,
        private readonly Security $security,
        private readonly AdminInvitationPages $adminPages,
        private readonly AdminInvitationEnrollment $adminEnrollment,
        #[Autowire(service: 'limiter.invitation_link_ip')]
        private readonly RateLimiterFactory $linkIpLimiter,
        #[Autowire(service: 'limiter.invitation_delegation')]
        private readonly RateLimiterFactory $delegationLimiter,
        #[Autowire(service: 'limiter.invitation_enroll_ip')]
        private readonly RateLimiterFactory $enrollIpLimiter,
    ) {
    }

    #[Route('/invitation/{token}', name: 'app_invitation_link', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function open(string $token, Request $request): Response
    {
        if (!$this->linkIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooMany();
        }
        $resolution = $this->resolver->resolve($token);
        if (LinkStatus::Unknown === $resolution->status && LinkStatus::Unknown !== $this->adminEnrollment->resolve($token)->status) {
            // Invitation d'administrateur communal émise par CWS (LOT 07.1) : mêmes URL, pages dédiées.
            $request->getSession()->remove(self::SESSION_KEY);

            return $this->adminPages->open($token, $request);
        }
        if (!$resolution->isUsable()) {
            return $this->unusable($resolution);
        }
        // Le jeton quitte l'URL (Referer, historique, journaux) avant tout affichage.
        $request->getSession()->remove(AdminInvitationPages::SESSION_KEY);
        $request->getSession()->set(self::SESSION_KEY, $token);

        return $this->secured(new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->generateUrl('app_invitation')]));
    }

    #[Route('/invitation', name: 'app_invitation', methods: ['GET'])]
    public function landing(Request $request): Response
    {
        if ($request->getSession()->has(AdminInvitationPages::SESSION_KEY)) {
            return $this->adminPages->landing($request);
        }
        $resolution = $this->current($request);
        if (!$resolution->isUsable()) {
            $request->getSession()->remove(self::SESSION_KEY);

            return $this->unusable($resolution);
        }

        return $this->page($request, $resolution);
    }

    #[Route('/invitation/delegation', name: 'app_invitation_delegate', methods: ['POST'])]
    public function delegate(Request $request): Response
    {
        $resolution = $this->current($request);
        $link = $resolution->link;
        if (!$resolution->isUsable() || null === $link) {
            return $this->unusable($resolution);
        }
        $email = $this->text($request->request->all()['administrator_email'] ?? '');
        if (!$this->isCsrfTokenValid('invitation_delegate', $this->text($request->request->all()['_token'] ?? ''))) {
            return $this->page($request, $resolution, ['error' => 'Le formulaire a expiré. Validez à nouveau.', 'administrator_email' => $email], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->delegationLimiter->create($link->id()->toRfc4122())->consume()->isAccepted()) {
            return $this->tooMany();
        }
        try {
            $result = $this->delegation->delegate($this->text($request->getSession()->get(self::SESSION_KEY)), $email);
        } catch (OperationNotAllowed) {
            return $this->page($request, $this->current($request), [], Response::HTTP_CONFLICT);
        } catch (DomainRuleViolation $e) {
            return $this->page($request, $resolution, ['error' => $e->getMessage(), 'administrator_email' => $email], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->page($request, $resolution, ['error' => 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez.', 'administrator_email' => $email], Response::HTTP_CONFLICT);
        }
        $this->addFlash($result->emailSent ? 'success' : 'warning', $result->emailSent
            ? sprintf('Un e-mail contenant un lien personnel a été envoyé à %s. Cette personne pourra créer son compte et accepter l’invitation.', $result->maskedAdministrator)
            : sprintf('L’e-mail destiné à %s n’a pas pu être envoyé. Vérifiez l’adresse puis réessayez.', $result->maskedAdministrator));

        return $this->secured($this->redirectToRoute('app_invitation', [], Response::HTTP_SEE_OTHER));
    }

    #[Route('/invitation/rejoindre', name: 'app_invitation_join', methods: ['POST'])]
    public function join(Request $request): Response
    {
        if ($request->getSession()->has(AdminInvitationPages::SESSION_KEY)) {
            return $this->adminPages->accept($request);
        }
        $resolution = $this->current($request);
        if (!$resolution->isUsable()) {
            return $this->unusable($resolution);
        }
        $post = $request->request->all();
        if (!$this->isCsrfTokenValid('invitation_join', $this->text($post['_token'] ?? ''))) {
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
            $result = $this->enrollment->enroll($resolution, $current, $signup, '1' === $this->text($post['attest'] ?? ''));
        } catch (OperationNotAllowed) {
            return $this->page($request, $this->current($request), [], Response::HTTP_CONFLICT);
        } catch (DomainRuleViolation $e) {
            return $this->page($request, $resolution, ['error' => $e->getMessage(), 'form' => $form], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->page($request, $resolution, ['error' => 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez.', 'form' => $form], Response::HTTP_CONFLICT);
        }
        if ($result->accountCreated) {
            $this->security->login($result->user, 'form_login', 'main'); // compte créé à l'instant : connexion directe
        }
        $request->getSession()->remove(self::SESSION_KEY);
        $this->addFlash('success', 'Vous êtes administrateur de la commune. Vous pouvez maintenant accepter l’invitation à l’événement.');

        return $this->redirectToRoute('app_municipal_event', ['municipalityId' => $result->municipalityId, 'eventId' => $result->eventId], Response::HTTP_SEE_OTHER);
    }

    private function current(Request $request): LinkResolution
    {
        $token = $request->getSession()->get(self::SESSION_KEY);

        return $this->resolver->resolve(\is_string($token) ? $token : '');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(Request $request, LinkResolution $resolution, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        if (!$resolution->isUsable() || null === $resolution->link) {
            return $this->unusable($resolution);
        }
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $kind = $resolution->link->kind();
        if (InvitationLinkKind::Contact === $kind) {
            $case = $resolution->communeAdministered ? 'contact_administered' : 'contact_delegate';
        } else {
            $case = 'admin_'.strtolower($this->enrollment->stepFor($resolution, $user)->value);
        }
        // Après connexion, retour sur cette page (lien personnel) ou sur la fiche de l'invitation (commune déjà administrée).
        $target = match ($case) {
            'contact_administered', 'admin_commune_administered' => $this->generateUrl('app_municipal_event', ['municipalityId' => $resolution->municipalityId, 'eventId' => $resolution->eventId]),
            'admin_login_required' => $this->generateUrl('app_invitation'),
            default => null,
        };
        if (null !== $target) {
            $request->getSession()->set('_security.main.target_path', $target);
        }

        return $this->secured($this->render('onboarding/invitation.html.twig', $extra + [
            'case' => $case,
            'r' => $resolution,
            'error' => null,
            'form' => ['first_name' => '', 'last_name' => ''],
            'administrator_email' => '',
            'invited_email_masked' => InvitationLinkKind::Administrator === $kind ? InvitationDispatcher::mask($resolution->link->recipientEmail()) : null,
        ], new Response('', $status)));
    }

    private function unusable(LinkResolution $resolution): Response
    {
        $status = LinkStatus::Unknown === $resolution->status ? Response::HTTP_NOT_FOUND : Response::HTTP_GONE;

        return $this->secured($this->render('onboarding/unusable.html.twig', ['status' => $resolution->status->value], new Response('', $status)));
    }

    private function tooMany(): Response
    {
        return $this->secured($this->render('onboarding/unusable.html.twig', ['status' => 'TOO_MANY'], new Response('', Response::HTTP_TOO_MANY_REQUESTS)));
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
