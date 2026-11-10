<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Application\Result\RevocationOutcome;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\RevocationReason;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Écran « Droits du laissez-passer » d'une commune et révocation de son autorisation (LOT 05.5.1, DM-116 §2, DM-119). Aucune règle ici : droits,
 * périmètre, verrous, motif, audit et notification relèvent des services ; la commune et la demande de l'URL ne sont jamais crues. Toute
 * écriture est un POST protégé par un jeton CSRF, avec confirmation explicite.
 *
 * Réponses : 403 rôle INSTRUCTOR/MUNICIPALITY_ADMIN absent ; 404 uniforme hors périmètre ; 409 participation non active ou conflit technique ;
 * 422 formulaire de révocation invalide (saisies conservées). `no-store` partout.
 */
final class PermitRightsController extends AbstractController
{
    public function __construct(
        private readonly PermitRightsOverviewReader $overviews,
        private readonly AuthorizationRevocationService $revocation,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits', name: 'app_municipal_permit_rights', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);

        return $this->render('permit/rights.html.twig', $this->context($user, $municipalityId, $requestId, $overview), $this->protected(Response::HTTP_OK));
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/revocation', name: 'app_municipal_permit_revoke', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function revoke(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $this->remember($request, $municipalityId);
        $form = ['reason' => '', 'comment' => '', 'confirmed' => false];
        $errors = [];
        $status = Response::HTTP_OK;
        if (!$overview->canRevoke) {
            $status = Response::HTTP_CONFLICT;
        } elseif ($request->isMethod('POST')) {
            $reason = $request->request->get('reason');
            $comment = $request->request->get('comment');
            $form = [
                'reason' => \is_string($reason) ? $reason : '',
                'comment' => \is_string($comment) ? $comment : '',
                'confirmed' => '1' === $request->request->get('confirmed'),
            ];
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid($this->csrfId($requestId), \is_string($token) ? $token : '')) {
                $errors['form'] = 'Le formulaire a expiré : aucune révocation n’a été enregistrée. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    $outcome = $this->revocation->revoke($user, $municipalityId, $requestId, $form['reason'], $form['comment'], $form['confirmed']);
                    $this->addFlash(RevocationOutcome::Revoked === $outcome ? 'success' : 'info', RevocationOutcome::Revoked === $outcome
                        ? 'Autorisation révoquée. Les droits de votre commune sont immédiatement inopérants ; les autres communes ne sont pas touchées.'
                        : 'Cette autorisation était déjà révoquée : rien n’a été modifié.');

                    return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                } catch (PermitAccessDenied) {
                    throw new AccessDeniedHttpException('Action non autorisée.');
                } catch (PermitAccessUnavailable) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'La participation de votre commune à cet événement n’est plus active : aucune révocation n’a été enregistrée.';
                } catch (OperationConflict $conflict) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = ConflictCause::Deadlock === $conflict->cause || ConflictCause::LockWaitTimeout === $conflict->cause
                        ? 'Un autre traitement est en cours : aucune révocation n’a été enregistrée. Réessayez dans un instant.'
                        : 'Les droits ont changé entre-temps : aucune révocation n’a été enregistrée. Relisez la page.';
                } catch (OperationNotAllowed $forged) {
                    throw $forged; // ressource inaccessible : refus uniforme (404), jamais un message de formulaire
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[self::field($violation->getMessage())] = $violation->getMessage();
                }
            }
        }

        return $this->render('permit/revoke.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'reasons' => array_map(static fn (RevocationReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], RevocationReason::cases()),
            'comment_max' => PermitRevision::COMMENT_MAX_LENGTH,
            'form' => $form,
            'errors' => $errors,
            'action_url' => $this->generateUrl('app_municipal_permit_revoke', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId($requestId),
        ], $this->protected($status));
    }

    /** Champ de formulaire concerné par un message du service (affichage près du champ). */
    private static function field(string $message): string
    {
        return match (true) {
            str_contains($message, 'motif de révocation') => 'reason',
            str_contains($message, 'ommentaire') => 'comment',
            str_contains($message, 'Confirmez') => 'confirmed',
            default => 'form',
        };
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function overview(User $user, string $municipalityId, string $requestId): PermitRightsOverview
    {
        try {
            return $this->overviews->overview($user, $municipalityId, $requestId);
        } catch (PermitAccessDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }

    /** @return array<string, mixed> */
    private function context(User $user, string $municipalityId, string $requestId, PermitRightsOverview $overview): array
    {
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Droits du laissez-passer '.$overview->reference) + [
            'overview' => $overview,
            'revoke_page_url' => $this->generateUrl('app_municipal_permit_revoke', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'rights_url' => $this->generateUrl('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'extend_url' => $this->generateUrl('app_municipal_permit_period_extend', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'shorten_url' => $this->generateUrl('app_municipal_permit_period_shorten', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'zones_url' => $this->generateUrl('app_municipal_permit_zones', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'qr_url' => $this->generateUrl('app_municipal_permit_qr', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'vehicles_url' => $this->generateUrl('app_municipal_permit_vehicles', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'labels_url' => $this->generateUrl('app_municipal_permit_zone_labels', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'links_url' => $this->generateUrl('app_municipal_permit_access', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'back_url' => $this->generateUrl('app_municipal_request', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
        ];
    }

    private function protected(int $status): Response
    {
        $response = new Response('', $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function remember(Request $request, string $municipalityId): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
    }

    private function csrfId(string $requestId): string
    {
        return 'permit_revoke_'.$requestId;
    }
}
