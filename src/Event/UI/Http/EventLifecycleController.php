<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\EventDetailReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Service\EventLifecycleService;
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

/**
 * Cycle de vie d'un événement : activation, suspension (motif obligatoire), réactivation, clôture globale (DM-092, irréversible).
 * Aucune règle métier ici : EventLifecycleService décide sous transaction (prérequis d'activation relu sous verrou, DM-059 ;
 * clôture de toutes les participations dans la même transaction).
 *
 * La disponibilité de l'action vient de la fiche lue côté serveur pour la commune de l'URL (politique d'accès) : seule la commune
 * organisatrice, administrateur ou gestionnaire, la voit permise. Statuts HTTP comme les autres formulaires : 404 uniforme,
 * 403 action refusée, 422 saisie ou règle (page réaffichée, panneau ouvert, saisies conservées), 409 conflit transactionnel,
 * 303 après succès. Tout ce qui sert à réafficher la page est lu AVANT l'écriture.
 */
final class EventLifecycleController extends AbstractController
{
    /** @var array<string, array{EventAction, string, string}> action d'URL => [disponibilité requise, message de succès, panneau à rouvrir] */
    private const ACTIONS = [
        'activer' => [EventAction::Activate, 'L’événement est activé.', 'lactivate'],
        'suspendre' => [EventAction::Suspend, 'L’événement est suspendu.', 'lsuspend'],
        'reactiver' => [EventAction::Reactivate, 'L’événement est réactivé.', ''],
        'cloturer' => [EventAction::Close, 'L’événement est clôturé : il et ses participations sont désormais en consultation seule.', 'lclose'],
    ];

    public function __construct(
        private readonly EventDetailReader $details,
        private readonly EventLifecycleService $lifecycle,
        private readonly ShellContextFactory $shell,
        private readonly EventViewFactory $views,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/cycle/{action}', name: 'app_municipal_event_lifecycle', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'action' => 'activer|suspendre|reactiver|cloturer'], methods: ['POST'])]
    public function transition(string $municipalityId, string $eventId, string $action, Request $request): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Cycle de vie');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId); // 404 uniforme si inaccessible
        [$required, $success, $panel] = self::ACTIONS[$action];
        $availability = $detail->action($required);
        if (null === $availability || !$availability->allowed) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
        $post = $request->request->all();
        $reason = $this->text($post['reason'] ?? '');
        $render = fn (?string $alert, int $status, ?string $problem = null): Response => $this->render('municipal/event/show.html.twig', $this->views->page($shell, $detail, $municipalityId, $detail->name, $alert, ['problem' => $problem, 'open_panel' => '' === $panel ? null : $panel, 'suspend_reason' => $reason]), new Response('', $status));

        if (!$this->isCsrfTokenValid('event_lifecycle_'.$action.'_'.$eventId, $this->text($post['_token'] ?? ''))) {
            return $render('Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('suspendre' === $action && '' === trim($reason)) {
            return $render(null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Indiquez le motif de la suspension.');
        }

        try {
            match ($action) {
                'activer' => $this->lifecycle->activate($user, $eventId),
                'suspendre' => $this->lifecycle->suspend($user, $eventId, $reason),
                'reactiver' => $this->lifecycle->reactivate($user, $eventId),
                default => $this->lifecycle->close($user, $eventId),
            };
        } catch (OperationNotAllowed) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        } catch (DomainRuleViolation $e) {
            return $render(null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        } catch (OperationConflict) {
            return $render('Un autre traitement est en cours : rien n’a été enregistré. Vous pouvez réessayer.', Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', $success);

        return $this->redirectToRoute('app_municipal_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
