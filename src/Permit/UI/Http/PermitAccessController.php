<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Identity\Domain\User;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\Read\AccessOverview;
use App\Permit\Application\VerificationUrlFactory;
use App\Permit\Domain\ManualNotificationOutcome;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Remise du lien bénéficiaire par un agent (LOT 05.2, DM-114). Aucune règle ici : droits, périmètre, verrous et audit relèvent de
 * PermitAccessService ; la commune, la demande et le lien de l'URL ne sont jamais crus. Toute écriture est un POST protégé par un jeton CSRF.
 *
 * Réponses : 403 rôle INSTRUCTOR/MUNICIPALITY_ADMIN absent ; 404 uniforme hors périmètre (commune non concernée, demande sans laissez-passer) ;
 * 200 avec le lien émis, affiché UNE fois (jamais en session, en redirection ni dans l'URL ; `no-store`) ; 409 participation non active ou conflit.
 */
final class PermitAccessController extends AbstractController
{
    public function __construct(
        private readonly PermitAccessService $access,
        private readonly ShellContextFactory $shell,
        private readonly VerificationUrlFactory $urls,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/laissez-passer', name: 'app_municipal_permit_access', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);

        return $this->view($user, $municipalityId, $requestId, $overview, null, null, Response::HTTP_OK);
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/laissez-passer/liens', name: 'app_municipal_permit_access_issue', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['POST'])]
    public function issue(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        if (!$this->csrf('issue', $requestId, $request)) {
            $this->addFlash('error', 'Le formulaire a expiré : aucun lien n’a été créé. Recommencez.');

            return $this->back($municipalityId, $requestId);
        }
        try {
            $link = $this->access->issue($user, $municipalityId, $requestId);
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (PermitAccessUnavailable) {
            return $this->view($user, $municipalityId, $requestId, $this->overview($user, $municipalityId, $requestId), null, null, Response::HTTP_CONFLICT);
        } catch (OperationConflict $conflict) {
            $this->addFlash('error', ConflictCause::Deadlock === $conflict->cause || ConflictCause::LockWaitTimeout === $conflict->cause ? 'Un autre traitement est en cours : aucun lien n’a été créé. Réessayez dans un instant.' : 'Aucun lien n’a été créé. Réessayez.');

            return $this->back($municipalityId, $requestId);
        } catch (DomainRuleViolation $violation) {
            $this->addFlash('error', $violation->getMessage());

            return $this->back($municipalityId, $requestId);
        }
        // Domaine public de la configuration, jamais l'en-tête Host de la requête (avis « Generated URLs Can Point to a Host Chosen by the Client »).
        $url = $this->urls->absolute($this->generateUrl('app_beneficiary_pass', ['secret' => $link->secret]));

        return $this->view($user, $municipalityId, $requestId, $this->overview($user, $municipalityId, $requestId), $url, $link->expiresAt, Response::HTTP_OK);
    }

    /**
     * Nouvelle notification de mise à disposition (LOT 05.3.4) : une INTENTION est enregistrée, l'envoi est fait par le distributeur, jamais dans
     * cette requête. CSRF obligatoire ; le formulaire porte un identifiant unique qui rend le double clic inoffensif. Redirection (POST/redirect/GET).
     */
    #[Route('/communes/{municipalityId}/demandes/{requestId}/laissez-passer/notification', name: 'app_municipal_permit_access_notify', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['POST'])]
    public function notify(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        if (!$this->csrf('notify', $requestId, $request)) {
            $this->addFlash('error', 'Le formulaire a expiré : aucune notification n’a été programmée. Recommencez.');

            return $this->back($municipalityId, $requestId);
        }
        $nonce = $request->request->get('request_id');
        try {
            $outcome = $this->access->requestNotification($user, $municipalityId, $requestId, \is_string($nonce) ? $nonce : '');
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (PermitAccessUnavailable) {
            return $this->view($user, $municipalityId, $requestId, $this->overview($user, $municipalityId, $requestId), null, null, Response::HTTP_CONFLICT);
        } catch (OperationConflict) {
            $this->addFlash('error', 'Un autre traitement est en cours : aucune notification n’a été programmée. Réessayez dans un instant.');

            return $this->back($municipalityId, $requestId);
        } catch (DomainRuleViolation $violation) {
            $this->addFlash('error', $violation->getMessage());

            return $this->back($municipalityId, $requestId);
        }
        match ($outcome) {
            ManualNotificationOutcome::Created => $this->addFlash('success', 'Un nouveau message de mise à disposition est programmé : il contiendra un nouveau lien et sera envoyé sous peu. Les liens existants restent valables.'),
            ManualNotificationOutcome::Duplicate => $this->addFlash('info', 'Cette demande a déjà été enregistrée : un seul message sera envoyé.'),
            ManualNotificationOutcome::AlreadyPending => $this->addFlash('info', 'Un message de mise à disposition est déjà en attente d’envoi : aucun second message n’a été programmé.'),
            ManualNotificationOutcome::TooSoon => $this->addFlash('info', 'Un renvoi vient d’être demandé (moins de 5 minutes) : aucun nouveau message n’a été programmé. Réessayez dans quelques minutes, ou remettez le lien en main propre (section « Liens du bénéficiaire »).'),
            ManualNotificationOutcome::DailyLimitReached => $this->addFlash('info', 'Limite atteinte : 3 renvois par laissez-passer sur 24 heures. Aucun nouveau message n’a été programmé. La remise du lien par un agent (section « Liens du bénéficiaire ») reste possible.'),
        };

        return $this->back($municipalityId, $requestId);
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/laissez-passer/liens/{linkId}/revoquer', name: 'app_municipal_permit_access_revoke', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID, 'linkId' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $municipalityId, string $requestId, string $linkId, Request $request): Response
    {
        $user = $this->user();
        $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        if (!$this->csrf('revoke', $requestId, $request)) {
            $this->addFlash('error', 'Le formulaire a expiré : le lien n’a pas été révoqué. Recommencez.');

            return $this->back($municipalityId, $requestId);
        }
        try {
            $done = $this->access->revoke($user, $municipalityId, $requestId, $linkId);
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (PermitAccessUnavailable) {
            return $this->view($user, $municipalityId, $requestId, $this->overview($user, $municipalityId, $requestId), null, null, Response::HTTP_CONFLICT);
        } catch (OperationConflict) {
            $this->addFlash('error', 'Un autre traitement est en cours : le lien n’a pas été révoqué. Réessayez dans un instant.');

            return $this->back($municipalityId, $requestId);
        }
        $this->addFlash($done ? 'success' : 'info', $done ? 'Lien révoqué : il ne donne plus accès au laissez-passer.' : 'Ce lien était déjà révoqué.');

        return $this->back($municipalityId, $requestId);
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function overview(User $user, string $municipalityId, string $requestId): AccessOverview
    {
        try {
            return $this->access->overview($user, $municipalityId, $requestId);
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }

    private function view(User $user, string $municipalityId, string $requestId, AccessOverview $overview, ?string $newLink, ?\DateTimeImmutable $newExpires, int $status): Response
    {
        $route = ['municipalityId' => $municipalityId, 'requestId' => $requestId];
        $response = new Response('', $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $this->render('permit/access.html.twig', $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Laissez-passer '.$overview->reference) + [
            'overview' => $overview,
            'new_link' => $newLink,
            'new_expires' => $newExpires,
            'issue_url' => $this->generateUrl('app_municipal_permit_access_issue', $route),
            'revoke_url' => $this->generateUrl('app_municipal_permit_access_revoke', $route + ['linkId' => '00000000-0000-7000-8000-000000000000']),
            'csrf_issue_id' => $this->csrfId('issue', $requestId),
            'csrf_revoke_id' => $this->csrfId('revoke', $requestId),
            'notify_url' => $this->generateUrl('app_municipal_permit_access_notify', $route),
            'csrf_notify_id' => $this->csrfId('notify', $requestId),
            'notify_request_id' => Uuid::v7()->toRfc4122(),
            'back_url' => $this->generateUrl('app_municipal_request', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
        ], $response);
    }

    private function back(string $municipalityId, string $requestId): RedirectResponse
    {
        return $this->redirectToRoute('app_municipal_permit_access', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }

    private function remember(Request $request, string $municipalityId): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
    }

    private function csrf(string $action, string $requestId, Request $request): bool
    {
        $token = $request->request->get('_token');

        return $this->isCsrfTokenValid($this->csrfId($action, $requestId), \is_string($token) ? $token : '');
    }

    private function csrfId(string $action, string $requestId): string
    {
        return 'permit_access_'.$action.'_'.$requestId;
    }
}
