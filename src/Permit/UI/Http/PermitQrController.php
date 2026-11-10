<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

use App\Identity\Domain\User;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\TokenReplacementResult;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\TokenReplacementReason;
use App\Permit\Domain\TokenReplacementRules;
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
use Symfony\Component\Uid\Uuid;

/**
 * Remplacer le QR code d'un laissez-passer (LOT 05.5.5). Aucune règle ici : le service décide, relit l'état sous verrou et écrit ; la commune, la demande et l'identifiant
 * d'opération du formulaire ne sont jamais crus. Le QR est COMMUN à toutes les communes : l'écran l'annonce, nomme les autres communes et exige la confirmation de l'effet
 * global. L'identifiant d'opération est généré à l'affichage et rendu à l'identique en cas de refus : un double envoi ne remplace qu'une fois. Saisies conservées en cas de refus.
 *
 * Réponses : 403 rôle MUNICIPALITY_ADMIN absent ; 404 uniforme hors périmètre ; 409 action impossible ou conflit technique ; 422 saisie refusée. `no-store` partout. Le motif
 * n'est jamais affiché hors de l'historique de la commune ; aucun jeton n'est écrit dans la page, les messages ni les journaux.
 */
final class PermitQrController extends AbstractController
{
    public function __construct(
        private readonly PermitRightsOverviewReader $overviews,
        private readonly PermitTokenReplacementService $replacements,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/droits/qr', name: 'app_municipal_permit_qr', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function replace(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $overview = $this->overview($user, $municipalityId, $requestId);
        $panel = $overview->qr ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $this->remember($request, $municipalityId);
        $blocked = !$panel->canReplace ? $panel->blockedReason : '';
        $form = ['operation' => Uuid::v7()->toRfc4122(), 'reason' => '', 'comment' => '', 'reference' => '', 'confirm' => false];
        $errors = [];
        $status = '' !== $blocked ? Response::HTTP_CONFLICT : Response::HTTP_OK;
        if ('' === $blocked && $request->isMethod('POST')) {
            $form = [
                'operation' => self::text($request, 'operation_id'),
                'reason' => self::text($request, 'reason'),
                'comment' => self::text($request, 'comment'),
                'reference' => self::text($request, 'reference'),
                'confirm' => '1' === $request->request->get('confirm_global'),
            ];
            if (!$this->isCsrfTokenValid($this->csrfId($requestId), self::text($request, '_token'))) {
                $errors['form'] = 'Le formulaire a expiré : rien n’a été enregistré. Recommencez.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                try {
                    $result = $this->replacements->replace($user, $municipalityId, $requestId, new TokenReplacementRequest($form['operation'], $form['reason'], $form['comment'], $form['confirm'], $form['reference']));
                    foreach (self::done($result) as [$variant, $message]) {
                        $this->addFlash($variant, $message);
                    }

                    return $this->redirectToRoute('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
                } catch (PermitAccessDenied) {
                    throw new AccessDeniedHttpException('Action non autorisée.');
                } catch (PermitAccessUnavailable) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = 'La participation de votre commune à cet événement n’est plus active : rien n’a été enregistré.';
                } catch (OperationConflict $conflict) {
                    $status = Response::HTTP_CONFLICT;
                    $errors['form'] = ConflictCause::Deadlock === $conflict->cause || ConflictCause::LockWaitTimeout === $conflict->cause
                        ? 'Un autre traitement est en cours : rien n’a été enregistré. Réessayez dans un instant (le même envoi ne remplacera le QR code qu’une fois).'
                        : 'Les droits ont changé entre-temps : rien n’a été enregistré. Relisez la page.';
                } catch (OperationNotAllowed $forged) {
                    throw $forged;
                } catch (DomainRuleViolation $violation) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    $errors[self::field($violation->getMessage())] = $violation->getMessage();
                }
            }
        }

        if (TokenReplacementRules::containsToken($form['comment'])) {
            $form['comment'] = ''; // un jeton saisi dans un commentaire n'est JAMAIS ré-affiché (ni dans l'erreur, ni dans le champ)
        }

        return $this->render('permit/qr.html.twig', $this->context($user, $municipalityId, $requestId, $overview) + [
            'blocked' => $blocked,
            'form' => $form,
            'errors' => $errors,
            'reasons' => array_map(static fn (TokenReplacementReason $r): array => ['code' => $r->value, 'label' => $r->label(), 'needsComment' => $r->needsComment()], TokenReplacementReason::cases()),
            'comment_max' => PermitRevision::COMMENT_MAX_LENGTH,
            'action_url' => $this->generateUrl('app_municipal_permit_qr', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId($requestId),
            'ask_reference' => $panel->closeToLast || isset($errors['reference']),
        ], $this->protected($status));
    }

    private static function text(Request $request, string $name): string
    {
        $value = $request->request->get($name);

        return \is_string($value) ? $value : '';
    }

    /** @return list<array{string, string}> */
    private static function done(TokenReplacementResult $result): array
    {
        if ($result->replayed) {
            return [['info', sprintf('Ce remplacement (n° %d) avait déjà été effectué : rien n’a été modifié une seconde fois.', $result->sequence)]];
        }
        $messages = [['success', sprintf('QR code remplacé (remplacement n° %d). L’ancien QR code ne fonctionne plus. Le bénéficiaire est prévenu sans attendre.', $result->sequence)]];
        if (!$result->recipientAvailable) {
            $messages[] = ['info', 'Aucune adresse e-mail n’est enregistrée : transmettez au bénéficiaire son lien personnel (ou émettez-en un nouveau) depuis « Liens et notification du bénéficiaire ». Ce lien affiche le nouveau QR code et permet de télécharger un PDF à jour.'];
        }
        if ($result->unusual) {
            $messages[] = ['info', 'Ce remplacement est signalé comme inhabituel (plusieurs remplacements en 24 heures) : il n’est pas bloqué.'];
        }

        return $messages;
    }

    private static function field(string $message): string
    {
        return match (true) {
            str_contains($message, 'Confirmez') => 'confirm',
            str_contains($message, 'ressaisissez la référence') => 'reference',
            str_contains($message, 'motif du remplacement') => 'reason',
            str_contains($message, 'ommentaire') => 'comment',
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
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', 'QR code du laissez-passer '.$overview->reference) + [
            'overview' => $overview,
            'rights_url' => $this->generateUrl('app_municipal_permit_rights', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
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
        return 'permit_qr_'.$requestId;
    }
}
