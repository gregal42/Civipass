<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\EventZoneReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\ZoneBoard;
use App\Event\Application\Read\ZoneView;
use App\Event\Application\Service\EventZoneService;
use App\Event\Domain\ZoneType;
use App\Event\UI\Http\Form\ZoneFormData;
use App\Event\UI\Http\Form\ZoneFormTranslator;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Zones d'un événement : consultation (les siennes et, en lecture seule, celles des communes visibles), création,
 * modification, confirmation, suspension, réactivation, suppression logique. Aucune règle métier ici : EventZoneService décide
 * sous transaction.
 *
 * Une commune n'agit que sur SES zones : la zone visée doit figurer, avec le drapeau « sienne », dans le tableau des zones lu
 * côté serveur pour la commune de l'URL ; l'identifiant de participation d'une création vient aussi de ce tableau, jamais du
 * client. Statuts HTTP : 404 uniforme (inaccessible, zone absente ou non visible), 403 (action refusée, zone d'une autre commune),
 * 422 (saisie ou règle, saisies conservées), 409 (conflit, nouvelle tentative manuelle), 303 après succès. Tout ce qui sert à
 * réafficher une page est lu AVANT l'écriture (l'EntityManager peut être fermé après un échec).
 */
final class EventZoneController extends AbstractController
{
    /** @var array<string, array{EventAction, string}> action d'URL => [disponibilité requise, message de succès] */
    private const ACTIONS = [
        'confirmer' => [EventAction::ConfirmZone, 'La zone a été confirmée.'],
        'suspendre' => [EventAction::SuspendZone, 'La zone a été suspendue.'],
        'reactiver' => [EventAction::ReactivateZone, 'La zone a été réactivée.'],
        'supprimer' => [EventAction::DeleteZone, 'La zone a été supprimée.'],
    ];

    private const FIELD_IDS = ['label' => 'z-label', 'type' => 'z-type', 'description' => 'z-desc', 'whole_confirmed' => 'z-whole'];

    public function __construct(
        private readonly EventZoneReader $reader,
        private readonly EventZoneService $zones,
        private readonly ZoneFormTranslator $translator,
        private readonly ShellContextFactory $shell,
        private readonly EventViewFactory $views,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones', name: 'app_municipal_event_zones', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET'])]
    public function list(string $municipalityId, string $eventId): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);

        return $this->render('municipal/event/zones.html.twig', $this->page($shell, $board, $municipalityId, 'Zones · '.$board->detail->name));
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/nouvelle', name: 'app_municipal_event_zone_new', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function create(string $municipalityId, string $eventId, Request $request): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        $create = $board->create;
        if (null === $create || !$create->allowed || null === $board->ownParticipationId) {
            throw $this->denied();
        }
        $view = $this->page($shell, $board, $municipalityId, 'Ajouter une zone', null, [
            'mode' => 'new',
            'heading' => 'Ajouter une zone',
            'zone' => null,
            'action_url' => $this->generateUrl('app_municipal_event_zone_new', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            'cancel_url' => $this->generateUrl('app_municipal_event_zones', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            'csrf_id' => 'zone_create_'.$eventId,
            'type_options' => $this->typeOptions(),
        ]);
        if (!$request->isMethod('POST')) {
            return $this->form($view, new ZoneFormData());
        }

        $data = ZoneFormData::fromPost($request->request->all());
        if (!$this->isCsrfTokenValid($view['csrf_id'], $this->token($request))) {
            return $this->form($view, $data, alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$command, $errors] = $this->translator->forCreate($data);
        if (null === $command) {
            return $this->form($view, $data, $errors, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->zones->create($this->currentUser(), $board->ownParticipationId, $command);
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (DomainRuleViolation $e) {
            return $this->form($view, $data, [], domainError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->form($view, $data, [], alert: $this->conflictMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', 'La zone a été créée.');

        return $this->toZone($municipalityId, $eventId, $result->zoneId);
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}', name: 'app_municipal_event_zone', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $eventId, string $zoneId): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        $zone = $board->zone($zoneId) ?? throw new OperationNotAllowed('Ressource inaccessible.');

        return $this->render('municipal/event/zone.html.twig', $this->zonePage($shell, $board, $municipalityId, $zone));
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/modifier', name: 'app_municipal_event_zone_edit', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $municipalityId, string $eventId, string $zoneId, Request $request): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        $zone = $this->ownZone($board, $zoneId, EventAction::UpdateZone);
        $view = $this->zonePage($shell, $board, $municipalityId, $zone, null, [
            'mode' => 'edit',
            'heading' => 'Modifier la zone',
            'action_url' => $this->generateUrl('app_municipal_event_zone_edit', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'zoneId' => $zoneId]),
            'cancel_url' => $this->generateUrl('app_municipal_event_zone', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'zoneId' => $zoneId]),
            'csrf_id' => 'zone_update_'.$zoneId,
            'type_options' => $this->typeOptions(),
        ]);
        if (!$request->isMethod('POST')) {
            return $this->form($view, new ZoneFormData($zone->label, $zone->type->value, $zone->description ?? ''));
        }

        $data = ZoneFormData::fromPost($request->request->all());
        if (!$this->isCsrfTokenValid($view['csrf_id'], $this->token($request))) {
            return $this->form($view, $data, alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        [$command, $errors] = $this->translator->forUpdate($data);
        if (null === $command) {
            return $this->form($view, $data, $errors, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->zones->update($this->currentUser(), $zoneId, $command);
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (DomainRuleViolation $e) {
            return $this->form($view, $data, [], domainError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->form($view, $data, [], alert: $this->conflictMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', $result->changed ? 'Les modifications ont été enregistrées.' : 'Aucune modification à enregistrer : les informations sont inchangées.');

        return $this->toZone($municipalityId, $eventId, $zoneId);
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/zones/{zoneId}/{action}', name: 'app_municipal_event_zone_action', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID, 'zoneId' => Requirement::UUID, 'action' => 'confirmer|suspendre|reactiver|supprimer'], methods: ['POST'])]
    public function action(string $municipalityId, string $eventId, string $zoneId, string $action, Request $request): Response
    {
        [$shell, $board] = $this->load($municipalityId, $eventId);
        [$required, $success] = self::ACTIONS[$action];
        $zone = $this->ownZone($board, $zoneId, $required);
        $render = fn (?string $alert, int $status, ?string $problem = null): Response => $this->render('municipal/event/zone.html.twig', $this->zonePage($shell, $board, $municipalityId, $zone, $alert, ['problem' => $problem, 'open_panel' => 'supprimer' === $action ? 'zdelete' : ('suspendre' === $action ? 'zsuspend' : null)]), new Response('', $status));

        if (!$this->isCsrfTokenValid('zone_'.$action.'_'.$zoneId, $this->token($request))) {
            return $render('Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $user = $this->currentUser();
        try {
            match ($action) {
                'confirmer' => $this->zones->confirm($user, $zoneId),
                'suspendre' => $this->zones->suspend($user, $zoneId),
                'reactiver' => $this->zones->reactivate($user, $zoneId),
                default => $this->zones->delete($user, $zoneId),
            };
        } catch (OperationNotAllowed) {
            throw $this->denied();
        } catch (DomainRuleViolation $e) {
            return $render(null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        } catch (OperationConflict) {
            return $render($this->conflictMessage(), Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', $success);

        return 'supprimer' === $action
            ? $this->redirectToRoute('app_municipal_event_zones', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER)
            : $this->toZone($municipalityId, $eventId, $zoneId);
    }

    /**
     * @return array{array<string, mixed>, ZoneBoard}
     */
    private function load(string $municipalityId, string $eventId): array
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Zones');
        $board = $this->reader->forEvent($user, $municipalityId, $eventId); // 404 uniforme si inaccessible

        return [$shell, $board];
    }

    /**
     * Zone de la commune de l'URL sur laquelle l'action demandée est disponible, sinon 404 (zone absente ou non visible) ou 403.
     */
    private function ownZone(ZoneBoard $board, string $zoneId, EventAction $required): ZoneView
    {
        $zone = $board->zone($zoneId) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $availability = $zone->visibleAction($required);
        if (!$zone->own || null === $availability || !$availability->allowed) {
            throw $this->denied();
        }

        return $zone;
    }

    /**
     * @param array<string, mixed> $shell
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function page(array $shell, ZoneBoard $board, string $municipalityId, string $title, ?string $alert = null, array $extra = []): array
    {
        return $this->views->page($shell, $board->detail, $municipalityId, $title, $alert, ['board' => $board] + $extra);
    }

    /**
     * @param array<string, mixed> $shell
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function zonePage(array $shell, ZoneBoard $board, string $municipalityId, ZoneView $zone, ?string $alert = null, array $extra = []): array
    {
        return $this->page($shell, $board, $municipalityId, $zone->label, $alert, [
            'zone' => $zone,
            'group' => $board->groupOf($zone->zoneId),
            'problem' => null,
            'show_url' => $this->generateUrl('app_municipal_event_zone', ['municipalityId' => $municipalityId, 'eventId' => $board->detail->eventId, 'zoneId' => $zone->zoneId]),
            'edit_url' => $this->generateUrl('app_municipal_event_zone_edit', ['municipalityId' => $municipalityId, 'eventId' => $board->detail->eventId, 'zoneId' => $zone->zoneId]),
        ] + $extra);
    }

    /**
     * @param array<string, mixed>  $view
     * @param array<string, string> $errors
     */
    private function form(array $view, ZoneFormData $data, array $errors = [], ?string $alert = null, ?string $domainError = null, int $status = Response::HTTP_OK): Response
    {
        $summary = [];
        foreach (self::FIELD_IDS as $key => $id) {
            if (isset($errors[$key])) {
                $summary[] = ['target' => $id, 'message' => $errors[$key]];
            }
        }
        if (null !== $domainError) {
            $summary[] = ['target' => 'zone-form', 'message' => $domainError];
        }
        $view['alert'] = $alert;

        return $this->render('municipal/event/zone_form.html.twig', $view + ['data' => $data, 'errors' => $errors, 'summary' => $summary], new Response('', $status));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        $labels = [
            ZoneType::WholeMunicipality->value => 'Commune entière',
            ZoneType::District->value => 'Quartier',
            ZoneType::Street->value => 'Rue',
            ZoneType::Locality->value => 'Lieu-dit',
            ZoneType::Custom->value => 'Personnalisée',
        ];

        return array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    private function toZone(string $municipalityId, string $eventId, string $zoneId): RedirectResponse
    {
        return $this->redirectToRoute('app_municipal_event_zone', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'zoneId' => $zoneId], Response::HTTP_SEE_OTHER);
    }

    private function token(Request $request): string
    {
        $token = $request->request->all()['_token'] ?? '';

        return \is_string($token) ? $token : '';
    }

    private function conflictMessage(): string
    {
        return 'Un autre traitement est en cours : rien n’a été enregistré. Vos saisies sont conservées, vous pouvez réessayer.';
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
