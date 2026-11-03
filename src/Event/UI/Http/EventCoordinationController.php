<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\EventDetailReader;
use App\Event\Application\InvitationCandidateReader;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Read\InvitationCandidates;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Coordination intercommunale : communes de l'événement, invitation d'une commune, actions sur SA participation (accepter,
 * suspendre, réactiver, clôturer). Aucune règle métier ici : EventParticipationService décide sous transaction.
 *
 * L'identifiant de la participation n'est JAMAIS lu depuis le client : il vient de la fiche lue côté serveur pour la commune
 * de l'URL (EventDetail::ownParticipationId), donc une commune ne peut agir que sur sa propre participation. Statuts HTTP comme pour
 * les formulaires d'événement : 404 uniforme, 403 action refusée, 422 saisie ou règle (page réaffichée, saisies conservées),
 * 409 conflit transactionnel, 303 après succès. Tout ce qui sert à réafficher une page est lu AVANT l'écriture.
 */
final class EventCoordinationController extends AbstractController
{
    /**
     * Refus MÉTIER d'une invitation que l'on peut communiquer tels quels à l'organisatrice (elle voit déjà toutes les
     * participations de son événement). Tout autre refus du moteur — dont les refus d'accès (rôle, statut de la commune,
     * ressource inaccessible) — devient la réponse uniforme 403 : aucun motif d'accès n'est jamais affiché.
     *
     * @var list<string>
     */
    private const COMMUNICABLE_REFUSALS = [
        'Cette commune est déjà invitée à cet événement ou y participe déjà.',
        'Cette commune ne peut plus être invitée à cet événement : sa participation est clôturée.',
        'Commune introuvable ou indisponible.',
    ];


    /** @var array<string, array{EventAction, string}> action d'URL => [disponibilité requise, message de succès] */
    private const PARTICIPATION_ACTIONS = [
        'accepter' => [EventAction::AcceptInvitation, 'L’invitation a été acceptée : votre commune participe à cet événement.'],
        'suspendre' => [EventAction::SuspendParticipation, 'Votre participation a été suspendue.'],
        'reactiver' => [EventAction::ReactivateParticipation, 'Votre participation a été réactivée.'],
        'cloturer' => [EventAction::CloseParticipation, 'Votre participation a été clôturée.'],
    ];

    public function __construct(
        private readonly EventDetailReader $details,
        private readonly InvitationCandidateReader $candidates,
        private readonly EventParticipationService $participations,
        private readonly ShellContextFactory $shell,
        private readonly EventViewFactory $views,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/communes', name: 'app_municipal_event_communes', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET'])]
    public function communes(string $municipalityId, string $eventId): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Communes');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);

        return $this->render('municipal/event/communes.html.twig', $this->views->page($shell, $detail, $municipalityId, 'Communes · '.$detail->name));
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/inviter', name: 'app_municipal_event_invite', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function invite(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Inviter une commune');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        $availability = $detail->action(EventAction::Invite);
        if (EventRelation::Organizer !== $detail->relation || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }
        $post = $request->isMethod('POST') ? $request->request->all() : [];
        $query = $this->text($post['q'] ?? $request->query->all()['q'] ?? '');
        $found = $this->candidates->search($user, $municipalityId, $eventId, $query);
        $view = fn (array $extra = [], ?string $alert = null, int $status = Response::HTTP_OK): Response => $this->render('municipal/event/invite.html.twig', $this->views->page($shell, $detail, $municipalityId, 'Inviter une commune', $alert, $extra + [
            'found' => $found,
            'query' => $query,
            'message' => $this->text($post['message'] ?? ''),
            'selected' => $this->text($post['municipality_id'] ?? ''),
            'summary' => [],
            'errors' => [],
            'csrf_id' => 'event_invite_'.$eventId,
            'min_length' => InvitationCandidateReader::MIN_LENGTH,
        ]), new Response('', $status));
        if (!$request->isMethod('POST')) {
            return $view();
        }

        $token = $this->text($post['_token'] ?? '');
        if (!$this->isCsrfTokenValid('event_invite_'.$eventId, $token)) {
            return $view(alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $selected = $this->text($post['municipality_id'] ?? '');
        $message = trim($this->text($post['message'] ?? ''));
        $errors = [];
        if (!Uuid::isValid($selected)) {
            $errors['municipality_id'] = 'Choisissez la commune à inviter.';
        }
        if ([] !== $errors) {
            return $view(['errors' => $errors, 'summary' => $this->summary($errors)], status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = $this->nameOf($found, $selected);
        try {
            $this->participations->invite($user, new InviteMunicipalityCommand($eventId, $selected, '' === $message ? null : $message));
        } catch (OperationNotAllowed $e) {
            if (!\in_array($e->getMessage(), self::COMMUNICABLE_REFUSALS, true)) {
                throw $this->denied(); // refus d'accès ou motif inconnu : réponse uniforme, jamais le motif
            }

            return $view(['summary' => [['target' => 'invite-form', 'message' => $e->getMessage()]]], status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DomainRuleViolation $e) {
            return $view(['summary' => [['target' => 'invite-form', 'message' => $e->getMessage()]]], status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $view(alert: $this->conflictMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', null === $name ? 'L’invitation a été envoyée.' : sprintf('L’invitation a été envoyée à %s.', $name));

        return $this->redirectToRoute('app_municipal_event_communes', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/participation/{action}', name: 'app_municipal_participation_action', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'action' => 'accepter|suspendre|reactiver|cloturer'], methods: ['POST'])]
    public function participation(string $municipalityId, string $eventId, string $action, Request $request): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Participation');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        [$required, $success] = self::PARTICIPATION_ACTIONS[$action];
        $availability = $detail->action($required);
        // Agit sur la participation de la commune de l'URL, jamais sur un identifiant fourni par le client.
        if (null === $detail->ownParticipationId || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }
        $post = $request->request->all();
        $reason = $this->text($post['reason'] ?? '');
        $render = fn (?string $alert, int $status, ?string $problem = null): Response => $this->renderAfterFailure($shell, $detail, $municipalityId, $action, $alert, $problem, $reason, $status);

        if (!$this->isCsrfTokenValid('participation_'.$action.'_'.$eventId, $this->text($post['_token'] ?? ''))) {
            return $render('Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('suspendre' === $action && '' === trim($reason)) {
            return $render(null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Indiquez la raison de la suspension.');
        }

        try {
            match ($action) {
                'accepter' => $this->participations->accept($user, $detail->ownParticipationId),
                'suspendre' => $this->participations->suspend($user, $detail->ownParticipationId, $reason),
                'reactiver' => $this->participations->reactivate($user, $detail->ownParticipationId),
                default => $this->participations->close($user, $detail->ownParticipationId),
            };
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (DomainRuleViolation $e) {
            return $render(null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        } catch (OperationConflict) {
            return $render($this->conflictMessage(), Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', $success);

        return 'accepter' === $action
            ? $this->redirectToRoute('app_municipal_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_municipal_event_communes', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed> $shell
     */
    private function renderAfterFailure(array $shell, EventDetail $detail, string $municipalityId, string $action, ?string $alert, ?string $problem, string $reason, int $status): Response
    {
        $extra = ['problem' => $problem, 'open_panel' => 'suspendre' === $action ? 'psuspend' : ('cloturer' === $action ? 'pclose' : null), 'suspend_reason' => $reason];
        $template = 'accepter' === $action ? 'municipal/event/show.html.twig' : 'municipal/event/communes.html.twig';

        return $this->render($template, $this->views->page($shell, $detail, $municipalityId, $detail->name, $alert, $extra), new Response('', $status));
    }

    private function nameOf(InvitationCandidates $found, string $municipalityId): ?string
    {
        foreach ($found->items as $candidate) {
            if ($candidate->municipalityId === $municipalityId) {
                return $candidate->name;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{target: string, message: string}>
     */
    private function summary(array $errors): array
    {
        $targets = ['municipality_id' => 'invite-candidates'];

        return array_map(static fn (string $key, string $message): array => ['target' => $targets[$key] ?? 'invite-form', 'message' => $message], array_keys($errors), $errors);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function conflictMessage(): string
    {
        return 'Un autre traitement est en cours : rien n’a été enregistré. Vous pouvez réessayer.';
    }

    private function denied(): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException('Action non autorisée.');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
