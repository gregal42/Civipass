<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\EventCreationFormReader;
use App\Event\Application\EventDetailReader;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\Time\EventTimezone;
use App\Event\UI\Http\Form\EventFormData;
use App\Event\UI\Http\Form\EventFormTranslator;
use App\Event\UI\Http\Form\FormTranslation;
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
 * Création et modification d'un événement. Aucune règle métier ici : le contrôleur vérifie le contexte (commune de l'URL,
 * jeton CSRF), traduit la requête en commande immuable et appelle EventCreationService::create ou
 * EventLifecycleService::update, qui reverifient droits, états et verrous sous transaction.
 *
 * Statuts HTTP : 404 uniforme si la commune ou l'événement est inaccessible ; 403 si l'action est refusée à un utilisateur qui
 * voit pourtant la ressource ; 422 (saisies conservées) pour une erreur de saisie, de règle métier ou un jeton expiré ; 409 en
 * cas de conflit transactionnel (rien n'est enregistré, saisies conservées, nouvelle tentative manuelle, jamais de retry
 * automatique) ; 303 vers la fiche après succès.
 *
 * Après un échec transactionnel Doctrine peut avoir FERMÉ l'EntityManager : tout ce qui sert à réafficher le formulaire (contexte
 * du layout, options, fuseau, détail de l'événement) est donc lu AVANT l'écriture ; rien n'est relu depuis la base ensuite.
 */
final class EventFormController extends AbstractController
{
    private const FIELD_IDS = [
        'name' => 'ev-name', 'type' => 'ev-type', 'description' => 'ev-desc', 'instructions' => 'ev-instr',
        'start_date' => 'ev-sd', 'start_time' => 'ev-st', 'end_date' => 'ev-ed', 'end_time' => 'ev-et',
        'timezone' => 'ev-tz', 'start_occurrence' => 'ev-start-occ', 'end_occurrence' => 'ev-end-occ',
    ];

    public function __construct(
        private readonly EventCreationService $creation,
        private readonly EventLifecycleService $lifecycle,
        private readonly EventDetailReader $details,
        private readonly EventCreationFormReader $creationForm,
        private readonly EventTypeRepository $types,
        private readonly EventFormTranslator $translator,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/nouveau', name: 'app_municipal_event_new', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function create(string $municipalityId, Request $request): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Créer un événement');
        $context = $this->creationForm->forMunicipality($user, $municipalityId);
        if (!$context->allowed) {
            throw $this->denied();
        }
        $resolved = null === $context->deducedTimezone ? null : EventTimezone::fromIdentifier($context->deducedTimezone);
        $types = $this->typeOptions();
        $view = $shell + [
            'mode' => 'new',
            'heading' => 'Créer un événement',
            'organizer_name' => $context->municipalityName,
            'type_options' => $types,
            'timezone_resolved' => $resolved?->identifier(),
            'timezone_options' => EventTimezone::SUPPORTED,
            'locked' => ['type' => false, 'timezone' => false, 'start' => false],
            'reference' => null,
            'action_url' => $this->generateUrl('app_municipal_event_new', ['municipalityId' => $municipalityId]),
            'cancel_url' => $this->generateUrl('app_municipal_events', ['municipalityId' => $municipalityId]),
            'csrf_id' => 'event_create',
        ];
        if (!$request->isMethod('POST')) {
            return $this->form($view, new EventFormData());
        }

        $data = EventFormData::fromPost($request->request->all());
        if (!$this->isCsrfTokenValid('event_create', $this->token($request))) {
            return $this->form($view, $data, alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $translation = $this->translator->forCreate($data, $resolved, array_column($types, 'value'));
        $command = $translation->command;
        if (!$translation->isValid() || !$command instanceof CreateEventCommand) {
            return $this->form($view, $data, $translation, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $created = $this->creation->create($user, $municipalityId, $command);
        } catch (OperationNotAllowed) {
            throw $this->denied(); // refus de droit : avant DomainRuleViolation, dont il hérite
        } catch (DomainRuleViolation $e) {
            return $this->form($view, $data, $translation, domainError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->form($view, $data, $translation, alert: $this->conflictMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', sprintf('L’événement a été créé en brouillon. Référence : %s.', $created->publicReference));

        return $this->toEvent($municipalityId, $created->eventId);
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/modifier', name: 'app_municipal_event_edit', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->currentUser();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Modifier l’événement');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId); // 404 uniforme si inaccessible
        $update = $detail->action(EventAction::Update);
        if (EventRelation::Organizer !== $detail->relation || null === $update || !$update->allowed) {
            throw $this->denied();
        }
        $types = $this->typeOptions($detail);
        $draft = EventStatus::Draft === $detail->status;
        $view = $shell + [
            'mode' => 'edit',
            'heading' => 'Modifier l’événement',
            'event' => ['id' => 'event', 'kicker' => 'Événement', 'value' => $detail->name, 'options' => []],
            'detail' => $detail,
            'organizer_name' => $detail->organizerMunicipalityName,
            'type_options' => $types,
            'timezone_resolved' => null,
            'timezone_options' => EventTimezone::SUPPORTED,
            'locked' => ['type' => !$draft, 'timezone' => !$draft, 'start' => !$draft],
            'reference' => $detail->publicReference,
            'action_url' => $this->generateUrl('app_municipal_event_edit', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            'cancel_url' => $this->generateUrl('app_municipal_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId]),
            'csrf_id' => 'event_update_'.$eventId,
        ];
        if (!$request->isMethod('POST')) {
            return $this->form($view, $this->translator->prefill($detail));
        }

        $data = EventFormData::fromPost($request->request->all());
        if (!$this->isCsrfTokenValid($view['csrf_id'], $this->token($request))) {
            return $this->form($view, $data, alert: 'Le formulaire a expiré. Vos saisies sont conservées : validez à nouveau.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $translation = $this->translator->forUpdate($data, $detail, array_column($types, 'value'));
        $command = $translation->command;
        if (!$translation->isValid() || !$command instanceof UpdateEventCommand) {
            return $this->form($view, $data, $translation, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->lifecycle->update($user, $eventId, $command);
        } catch (OperationNotAllowed) {
            throw $this->denied(); // refus de droit : avant DomainRuleViolation, dont il hérite
        } catch (DomainRuleViolation $e) {
            return $this->form($view, $data, $translation, domainError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OperationConflict) {
            return $this->form($view, $data, $translation, alert: $this->conflictMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', [] === $result->changedFields ? 'Aucune modification à enregistrer : les informations sont inchangées.' : 'Les modifications ont été enregistrées.');

        return $this->toEvent($municipalityId, $eventId);
    }

    /**
     * @param array<string, mixed> $view
     */
    private function form(array $view, EventFormData $data, ?FormTranslation $translation = null, ?string $alert = null, ?string $domainError = null, int $status = Response::HTTP_OK): Response
    {
        $errors = $translation->errors ?? [];
        $summary = [];
        foreach (self::FIELD_IDS as $key => $id) {
            if (isset($errors[$key])) {
                $summary[] = ['target' => $id, 'message' => $errors[$key]];
            }
        }
        if (null !== $domainError) {
            $summary[] = ['target' => 'event-form', 'message' => $domainError];
        }

        return $this->render('municipal/event/form.html.twig', $view + [
            'data' => $data,
            'errors' => $errors,
            'summary' => $summary,
            'choices' => $translation->choices ?? [],
            'alert' => $alert,
            'field_ids' => self::FIELD_IDS,
        ], new Response('', $status));
    }

    private function toEvent(string $municipalityId, string $eventId): RedirectResponse
    {
        return $this->redirectToRoute('app_municipal_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(?EventDetail $current = null): array
    {
        $options = [];
        foreach ($this->types->findActive() as $type) {
            $options[$type->code()] = ['value' => $type->code(), 'label' => $type->label()];
        }
        if (null !== $current && !isset($options[$current->typeCode])) {
            $options[$current->typeCode] = ['value' => $current->typeCode, 'label' => $current->typeLabel]; // type désactivé mais déjà porté par l'événement
        }

        return array_values($options);
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
