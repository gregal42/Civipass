<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\EventDetailReader;
use App\Event\Application\InvitationCandidateReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use App\Onboarding\Application\InvitationDeliveryReader;
use App\Onboarding\Application\InvitationDispatcher;
use App\Onboarding\Domain\InvitationDispatchResult;
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
        private readonly InvitationDispatcher $dispatcher,
        private readonly InvitationDeliveryReader $deliveries,
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

        return $this->render('municipal/event/communes.html.twig', $this->views->page($shell, $detail, $municipalityId, 'Communes · '.$detail->name, null, [
            // Seule la commune organisatrice (visibilité complète) voit l'état des e-mails d'invitation et peut les renvoyer.
            'deliveries' => EventRelation::Organizer === $detail->relation ? $this->deliveries->forEvent(Uuid::fromString($detail->eventId)) : [],
        ]));
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
            'contact_email' => $this->text($post['contact_email'] ?? ''),
            'selected' => $this->text($post['insee_code'] ?? ''),
            'summary' => [],
            'errors' => [],
            'csrf_id' => 'event_invite_'.$eventId,
            'min_length' => InvitationCandidateReader::MIN_LENGTH,
            'search_url' => $this->generateUrl('app_municipal_event_invite_candidates', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
        ]), new Response('', $status));
        if (!$request->isMethod('POST')) {
            return $view();
        }

        $token = $this->text($post['_token'] ?? '');
        if (!$this->isCsrfTokenValid('event_invite_'.$eventId, $token)) {
            return $view(alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $selected = $this->text($post['insee_code'] ?? '');
        $contact = trim($this->text($post['contact_email'] ?? ''));
        $message = trim($this->text($post['message'] ?? ''));
        $errors = [];
        if (1 !== preg_match('/^(\d{2}|2A|2B)\d{3}$/', $selected)) {
            $errors['insee_code'] = 'Choisissez la commune à inviter.';
        }
        if ('' === $contact) {
            $errors['contact_email'] = 'Indiquez l’adresse e-mail du destinataire de l’invitation.';
        } elseif (mb_strlen($contact) > 180 || false === filter_var($contact, \FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Cette adresse e-mail n’est pas valide.';
        }
        if ([] !== $errors) {
            return $view(['errors' => $errors, 'summary' => $this->summary($errors)], status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->dispatcher->invite($user, $eventId, $selected, $contact, '' === $message ? null : $message);
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
        $this->flashDispatch($result);

        return $this->redirectToRoute('app_municipal_event_communes', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    /**
     * Fragment HTML des résultats de recherche (recherche dynamique) ; la page elle-même fonctionne sans JavaScript.
     */
    #[Route('/communes/{municipalityId}/evenements/{eventId}/inviter/communes', name: 'app_municipal_event_invite_candidates', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET'])]
    public function candidates(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->currentUser();
        $this->shell->forMunicipality($user, $municipalityId, 'events', 'Inviter une commune');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        $availability = $detail->action(EventAction::Invite);
        if (EventRelation::Organizer !== $detail->relation || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }
        $query = $this->text($request->query->all()['q'] ?? '');
        $response = $this->render('municipal/event/_invite_candidates.html.twig', [
            'found' => $this->candidates->search($user, $municipalityId, $eventId, $query),
            'query' => $query,
            'selected' => '',
            'errors' => [],
            'min_length' => InvitationCandidateReader::MIN_LENGTH,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Renvoie l'invitation d'une commune encore « invitée » : nouveau lien (l'ancien est révoqué), adresse corrigeable. Le
     * `participationId` vient de la fiche lue côté serveur pour la commune organisatrice, jamais d'un identifiant non vérifié :
     * le répartiteur vérifie qu'il appartient bien à cet événement.
     */
    #[Route('/communes/{municipalityId}/evenements/{eventId}/invitations/{participationId}/renvoyer', name: 'app_municipal_event_invitation_resend', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'participationId' => Requirement::UUID], methods: ['POST'])]
    public function resend(string $municipalityId, string $eventId, string $participationId, Request $request): Response
    {
        $user = $this->currentUser();
        $this->shell->forMunicipality($user, $municipalityId, 'events', 'Renvoyer l’invitation');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        $availability = $detail->action(EventAction::Invite);
        if (EventRelation::Organizer !== $detail->relation || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }
        $post = $request->request->all();
        $back = $this->redirectToRoute('app_municipal_event_communes', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
        if (!$this->isCsrfTokenValid('event_resend_'.$participationId, $this->text($post['_token'] ?? ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré : rien n’a été envoyé. Recommencez.');

            return $back;
        }
        try {
            $result = $this->dispatcher->resend($user, $eventId, $participationId, $this->text($post['contact_email'] ?? ''));
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (DomainRuleViolation $e) {
            $this->addFlash('warning', $e->getMessage());

            return $back;
        } catch (OperationConflict) {
            $this->addFlash('warning', $this->conflictMessage());

            return $back;
        }
        $this->flashDispatch($result);

        return $back;
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

    private function flashDispatch(InvitationDispatchResult $result): void
    {
        $registered = $result->communeRegistered ? ' Cette commune n’était pas encore inscrite sur Civipass : elle l’est désormais, sans abonnement.' : '';
        if ($result->emailSent) {
            $this->addFlash('success', sprintf('L’invitation a été envoyée à %s : un e-mail a été adressé à %s.%s', $result->communeName, $result->maskedRecipient, $registered));

            return;
        }
        $this->addFlash('warning', sprintf('L’invitation de %s est enregistrée, mais l’e-mail n’a pas pu être envoyé à %s. Utilisez « Renvoyer l’invitation » pour réessayer.%s', $result->communeName, $result->maskedRecipient, $registered));
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{target: string, message: string}>
     */
    private function summary(array $errors): array
    {
        $targets = ['insee_code' => 'invite-candidates', 'contact_email' => 'inv-contact'];

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
