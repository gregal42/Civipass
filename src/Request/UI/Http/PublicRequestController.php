<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Event\Domain\EventRepository;
use App\Event\Domain\EventStatus;
use App\Event\Domain\PublicReference;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\Result\SubmittedRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Formulaire public de demande de laissez-passer (SCR-019 à SCR-025), sans compte : six étapes, une page serveur par étape,
 * brouillon en session. Le moteur du formulaire est partagé avec la saisie par un agent (AbstractRequestFormController).
 * L'événement se désigne par sa référence publique ; un événement en préparation n'existe pas pour le public (404) ; suspendu,
 * clos ou sans zone proposable, le dépôt est indisponible et AUCUN formulaire n'est servi.
 */
final class PublicRequestController extends AbstractRequestFormController
{
    public function __construct(
        RequestFormServices $services,
        private readonly EventRepository $events,
    ) {
        parent::__construct($services);
    }

    #[Route('/demandes/{eventReference}', name: 'app_public_request_start', requirements: ['eventReference' => 'EVT-\d{4}-\d{6,}'], methods: ['GET'])]
    public function start(string $eventReference): Response
    {
        return $this->redirectToRoute('app_public_request_step', ['eventReference' => $eventReference, 'step' => PublicRequestStep::Identity->value]);
    }

    /** La référence de la demande n'est dans aucune URL : la confirmation n'est lisible que par la session qui a déposé. */
    #[Route('/demandes/{eventReference}/confirmation', name: 'app_public_request_confirmation', requirements: ['eventReference' => 'EVT-\d{4}-\d{6,}'], methods: ['GET'])]
    public function confirmation(Request $request, string $eventReference): Response
    {
        return $this->confirmationPage($this->context($request, $eventReference));
    }

    #[Route('/demandes/{eventReference}/{step}', name: 'app_public_request_step', requirements: ['eventReference' => 'EVT-\d{4}-\d{6,}', 'step' => self::STEPS_REQUIREMENT], methods: ['GET', 'POST'])]
    public function stepAction(Request $request, string $eventReference, string $step): Response
    {
        return $this->step($request, $this->context($request, $eventReference), $step);
    }

    protected function deposit(RequestFormContext $c, SubmitRequestCommand $command): SubmittedRequest
    {
        return $this->s->submission->submitPublic($command, $c->draft->attemptKey());
    }

    private function context(Request $request, string $eventReference): RequestFormContext
    {
        $event = $this->events->findByPublicReference(PublicReference::fromString($eventReference));
        if (null === $event || EventStatus::Draft === $event->status()) {
            throw new NotFoundHttpException();
        }

        return new RequestFormContext(
            $event,
            new PublicRequestDraft($request->getSession(), $eventReference),
            'public_request_'.$eventReference,
            fn (PublicRequestStep $s): string => $this->generateUrl('app_public_request_step', ['eventReference' => $eventReference, 'step' => $s->value]),
            fn (): string => $this->generateUrl('app_public_request_confirmation', ['eventReference' => $eventReference]),
        );
    }
}
