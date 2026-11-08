<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Event\Domain\Time\AmbiguousLocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Identity\Domain\User;
use App\Request\Application\InstructorRoleRequired;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\MunicipalRequestReader;
use App\Request\Application\Read\DecisionPage;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Écrans de décision d'une part communale (LOT 04.2) : approbation en deux temps (durée → récapitulatif → confirmation) et refus.
 * Les contrôleurs ne portent AUCUNE règle métier : ils lisent (MunicipalRequestReader), valident la forme de la saisie
 * (ApprovalDuration::fromInput) et délèguent la décision à MunicipalInstructionService, qui revérifie droits, état et zones sous verrou.
 * Commune, demande et motif sont des identifiants non fiables ; toute écriture est un POST protégé par un jeton CSRF propre à la demande.
 *
 * Réponses : 403 rôle INSTRUCTOR absent ; 404 uniforme hors périmètre ; 422 saisie ou règle métier refusée (saisies conservées) ;
 * 303 après succès ou quand la fiche a changé entre-temps (part déjà décidée, décision devenue impossible) avec un message.
 */
final class MunicipalRequestDecisionController extends AbstractController
{
    private const FORM_FIELDS = ['mode', 'end_date', 'end_time', 'occurrence'];

    public function __construct(
        private readonly MunicipalRequestReader $requests,
        private readonly MunicipalInstructionService $instruction,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/approuver', name: 'app_municipal_request_approve', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function approve(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $shell = $this->context($user, $municipalityId, 'Approuver la demande');
        $page = $this->page($user, $municipalityId, $requestId);
        if (null !== ($redirect = $this->unavailable($page, $municipalityId, $requestId))) {
            return $redirect;
        }
        $this->remember($request, $municipalityId);

        if (!$request->isMethod('POST')) {
            return $this->renderApprove($shell, $page, $municipalityId, $requestId, $this->values([]), []);
        }

        $values = $this->values($request->request->all());
        if (!$this->csrf('approve', $requestId, $request)) {
            return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['form' => 'Le formulaire a expiré : rien n’a été enregistré. Vérifiez la durée puis recommencez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $duration = ApprovalDuration::fromInput($values['mode'], $values['end_date'], $values['end_time'], Occurrence::tryFrom($values['occurrence']));
        } catch (DomainRuleViolation $violation) {
            return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['duration' => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('edit' === $request->request->get('step')) {
            // Retour du récapitulatif au formulaire : les valeurs reviennent par POST (jamais dans l'URL).
            return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, []);
        }
        if ('confirm' !== $request->request->get('step')) {
            try {
                $preview = $this->instruction->previewApproval($user, $municipalityId, $requestId, $duration);
            } catch (AmbiguousLocalTime $ambiguous) {
                return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['duration' => $ambiguous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY, askOccurrence: true);
            } catch (InstructorRoleRequired) {
                throw new AccessDeniedHttpException('Action non autorisée.');
            } catch (OperationNotAllowed $denied) {
                return $this->notAllowed($denied, $user, $municipalityId, $requestId);
            } catch (DomainRuleViolation $violation) {
                return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['duration' => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->render('municipal/request/approve_review.html.twig', $shell + $this->common($page, $municipalityId, $requestId) + [
                'form' => $values,
                'window' => $preview->window,
                'problems' => $preview->problems,
                'action_url' => $this->generateUrl('app_municipal_request_approve', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
                'csrf_id' => $this->csrfId('approve', $requestId),
            ]);
        }

        try {
            $result = $this->instruction->approve($user, $municipalityId, $requestId, $duration);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (OperationConflict $conflict) {
            return $this->conflict($conflict, $municipalityId, $requestId, fn (string $message): Response => $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['form' => $message], Response::HTTP_CONFLICT));
        } catch (OperationNotAllowed $denied) {
            return $this->notAllowed($denied, $user, $municipalityId, $requestId);
        } catch (DomainRuleViolation $violation) {
            // Durée refusée par le moteur (fin dépassant l'événement, zone sans période dans la fenêtre…) : retour au formulaire, saisies conservées.
            return $this->renderApprove($shell, $page, $municipalityId, $requestId, $values, ['duration' => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY, askOccurrence: $violation instanceof AmbiguousLocalTime);
        }
        $this->addFlash('success', sprintf('La demande %s est approuvée pour toutes les zones demandées à %s.', $result->reference, $shell['active_municipality_name'] ?? 'votre commune'));

        return $this->toRequest($municipalityId, $requestId);
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}/refuser', name: 'app_municipal_request_refuse', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function refuse(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $shell = $this->context($user, $municipalityId, 'Refuser la demande');
        $page = $this->page($user, $municipalityId, $requestId);
        if (null !== ($redirect = $this->unavailable($page, $municipalityId, $requestId))) {
            return $redirect;
        }
        $this->remember($request, $municipalityId);

        if (!$request->isMethod('POST')) {
            return $this->renderRefuse($shell, $page, $municipalityId, $requestId, ['reason' => '', 'comment' => ''], []);
        }
        $post = $request->request->all();
        $values = ['reason' => \is_string($post['reason'] ?? null) ? $post['reason'] : '', 'comment' => \is_string($post['comment'] ?? null) ? mb_substr($post['comment'], 0, 2000) : ''];
        if (!$this->csrf('refuse', $requestId, $request)) {
            return $this->renderRefuse($shell, $page, $municipalityId, $requestId, $values, ['form' => 'Le formulaire a expiré : rien n’a été enregistré. Vérifiez le motif puis recommencez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->instruction->refuse($user, $municipalityId, $requestId, $values['reason'], $values['comment']);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (OperationConflict $conflict) {
            return $this->conflict($conflict, $municipalityId, $requestId, fn (string $message): Response => $this->renderRefuse($shell, $page, $municipalityId, $requestId, $values, ['form' => $message], Response::HTTP_CONFLICT));
        } catch (OperationNotAllowed $denied) {
            return $this->notAllowed($denied, $user, $municipalityId, $requestId);
        } catch (DomainRuleViolation $violation) {
            $field = '' === $values['reason'] || !$this->knownReason($page, $values['reason']) ? 'reason' : 'comment';

            return $this->renderRefuse($shell, $page, $municipalityId, $requestId, $values, [$field => $violation->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', sprintf('La demande %s est refusée pour toutes les zones demandées à %s.', $result->reference, $shell['active_municipality_name'] ?? 'votre commune'));

        return $this->toRequest($municipalityId, $requestId);
    }

    /**
     * @param array<string, mixed>  $shell
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderApprove(array $shell, DecisionPage $page, string $municipalityId, string $requestId, array $values, array $errors, int $status = Response::HTTP_OK, bool $askOccurrence = false): Response
    {
        return $this->render('municipal/request/approve.html.twig', $shell + $this->common($page, $municipalityId, $requestId) + [
            'form' => $values,
            'errors' => $errors,
            'summary' => array_map(static fn (string $target, string $message): array => ['target' => \in_array($target, ['duration', 'form'], true) ? 'f-mode' : 'f-'.$target, 'message' => $message], array_keys($errors), $errors),
            'ask_occurrence' => $askOccurrence || '' !== $values['occurrence'],
            'action_url' => $this->generateUrl('app_municipal_request_approve', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId('approve', $requestId),
        ], new Response('', $status));
    }

    /**
     * @param array<string, mixed>  $shell
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderRefuse(array $shell, DecisionPage $page, string $municipalityId, string $requestId, array $values, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('municipal/request/refuse.html.twig', $shell + $this->common($page, $municipalityId, $requestId) + [
            'form' => $values,
            'errors' => $errors,
            'summary' => array_map(static fn (string $target, string $message): array => ['target' => 'form' === $target ? 'f-reason' : 'f-'.$target, 'message' => $message], array_keys($errors), $errors),
            'action_url' => $this->generateUrl('app_municipal_request_refuse', ['municipalityId' => $municipalityId, 'requestId' => $requestId]),
            'csrf_id' => $this->csrfId('refuse', $requestId),
        ], new Response('', $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function common(DecisionPage $page, string $municipalityId, string $requestId): array
    {
        $route = ['municipalityId' => $municipalityId];

        return [
            'detail' => $page->detail,
            'reasons' => $page->refusalReasons,
            'requests_url' => $this->generateUrl('app_municipal_requests', $route),
            'list_url' => $this->generateUrl('app_municipal_requests', $route + ['evenement' => $page->detail->eventId]),
            'request_url' => $this->generateUrl('app_municipal_request', $route + ['requestId' => $requestId]),
        ];
    }

    /** Fiche déjà décidée ou instruction impossible : retour à la fiche avec l'explication, jamais un formulaire inutilisable. */
    private function unavailable(DecisionPage $page, string $municipalityId, string $requestId): ?RedirectResponse
    {
        if ($page->canDecide()) {
            return null;
        }
        $this->addFlash('warning', null !== $page->unavailableReason ? $page->unavailableReason : 'Cette demande a déjà fait l’objet d’une décision de votre commune : elle ne peut plus être modifiée.');

        return $this->toRequest($municipalityId, $requestId);
    }

    /**
     * Décision concurrente : la fiche affiche la décision enregistrée. Autre conflit (verrou, interblocage) : rien n'a été écrit, le formulaire
     * est réaffiché (409) avec les saisies déjà chargées en mémoire — jamais transportées dans l'URL ; le jeton CSRF est régénéré au rendu.
     *
     * @param callable(string): Response $redisplay
     */
    private function conflict(OperationConflict $conflict, string $municipalityId, string $requestId, callable $redisplay): Response
    {
        if (ConflictCause::StaleState === $conflict->cause) {
            $this->addFlash('warning', 'Cette demande vient d’être traitée par ailleurs. Actualisez la fiche : la décision enregistrée est affichée ci-dessous ; la vôtre n’a pas été appliquée.');

            return $this->toRequest($municipalityId, $requestId);
        }

        return $redisplay('Un autre traitement est en cours sur cette demande : rien n’a été enregistré. Vérifiez la saisie puis réessayez dans un instant.');
    }

    /**
     * Refus d'écriture (participation devenue non active, événement suspendu…) : message si la fiche reste lisible, sinon le refus uniforme 404.
     */
    private function notAllowed(OperationNotAllowed $denied, User $user, string $municipalityId, string $requestId): RedirectResponse
    {
        $page = $this->requests->decisionPage($user, $municipalityId, $requestId); // relève OperationNotAllowed (404 uniforme) si la ressource n'est plus accessible
        if ($page->canDecide()) {
            throw $denied;
        }
        $this->addFlash('warning', $page->unavailableReason ?? 'Cette demande a déjà fait l’objet d’une décision de votre commune.');

        return $this->toRequest($municipalityId, $requestId);
    }

    private function page(User $user, string $municipalityId, string $requestId): DecisionPage
    {
        try {
            return $this->requests->decisionPage($user, $municipalityId, $requestId);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(User $user, string $municipalityId, string $title): array
    {
        return $this->shell->forMunicipality($user, $municipalityId, 'requests', $title);
    }

    private function remember(Request $request, string $municipalityId): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
    }

    /**
     * Saisie du formulaire d'approbation, réduite à des chaînes de longueur bornée (rien n'est exécuté) ; le mode inconnu retombe sur H24
     * à l'affichage, et reste refusé tel quel par ApprovalDuration::fromInput à l'envoi.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{mode: string, end_date: string, end_time: string, occurrence: string}
     */
    private function values(array $raw): array
    {
        $values = ['mode' => DurationMode::H24->value, 'end_date' => '', 'end_time' => '', 'occurrence' => ''];
        foreach (self::FORM_FIELDS as $field) {
            if (\is_string($raw[$field] ?? null)) {
                $values[$field] = mb_substr(trim($raw[$field]), 0, 40);
            }
        }

        return $values;
    }

    private function knownReason(DecisionPage $page, string $code): bool
    {
        foreach ($page->refusalReasons as $reason) {
            if ($reason['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private function csrf(string $action, string $requestId, Request $request): bool
    {
        $token = $request->request->get('_token');

        return $this->isCsrfTokenValid($this->csrfId($action, $requestId), \is_string($token) ? $token : '');
    }

    private function csrfId(string $action, string $requestId): string
    {
        return 'municipal_request_'.$action.'_'.$requestId;
    }

    private function toRequest(string $municipalityId, string $requestId): RedirectResponse
    {
        return $this->redirectToRoute('app_municipal_request', ['municipalityId' => $municipalityId, 'requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
