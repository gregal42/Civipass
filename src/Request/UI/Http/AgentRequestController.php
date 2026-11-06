<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\AgentRequestAccess;
use App\Request\Application\AgentRequestDenied;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\Result\AgentRequestScope;
use App\Request\Application\Result\SubmittedRequest;
use App\Shell\ShellContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Saisie d'une demande par un agent municipal (MUNICIPALITY_ADMIN, INSTRUCTOR ou EVENT_MANAGER de la commune de l'URL) pour le compte d'un
 * bénéficiaire. Même moteur de formulaire que le dépôt public ; autres différences : l'événement se désigne par son identifiant
 * interne et est revérifié à chaque requête (AgentRequestAccess), le brouillon est propre à l'utilisateur, à sa commune et à
 * l'événement, et le dépôt passe par `submitByAgent`, qui conserve l'agent, sa commune et l'origine. Aucune route publique n'est
 * utilisée, et ces écrans n'ouvrent aucun accès à la consultation des demandes (réservée aux INSTRUCTOR).
 */
final class AgentRequestController extends AbstractRequestFormController
{
    private const BASE = '/communes/{municipalityId}/evenements/{eventId}/demandes/nouvelle';
    private const IDS = ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID];

    public function __construct(
        RequestFormServices $services,
        private readonly AgentRequestAccess $access,
        private readonly ShellContextFactory $shell,
    ) {
        parent::__construct($services);
    }

    /** « Nouvelle demande » depuis le menu : l'agent voit et choisit explicitement l'événement, même s'il n'y en a qu'un. */
    #[Route('/communes/{municipalityId}/demandes/nouvelle', name: 'app_municipal_request_new', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function choose(string $municipalityId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'new_request', 'Nouvelle demande');
        try {
            $events = $this->access->eligibleEvents($user, $municipalityId);
        } catch (AgentRequestDenied) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }

        return $this->render('municipal/request/new.html.twig', $shell + [
            'events' => $events,
            'start_urls' => array_column(array_map(fn ($e): array => [$e->eventId, $this->generateUrl('app_municipal_request_new_event', ['municipalityId' => $municipalityId, 'eventId' => $e->eventId])], $events), 1, 0),
        ]);
    }

    #[Route(self::BASE, name: 'app_municipal_request_new_event', requirements: self::IDS, methods: ['GET'])]
    public function start(string $municipalityId, string $eventId): Response
    {
        $this->scope($municipalityId, $eventId);

        return $this->redirectToRoute('app_municipal_request_new_step', ['municipalityId' => $municipalityId, 'eventId' => $eventId, 'step' => PublicRequestStep::Identity->value]);
    }

    /** Confirmation lisible par le seul agent (et la seule session) qui a déposé ; elle n'ouvre aucun droit de consultation. */
    #[Route(self::BASE.'/confirmation', name: 'app_municipal_request_new_confirmation', requirements: self::IDS, methods: ['GET'])]
    public function confirmation(Request $request, string $municipalityId, string $eventId): Response
    {
        return $this->confirmationPage($this->context($request, $municipalityId, $eventId, $this->scope($municipalityId, $eventId, requireOperational: false)));
    }

    #[Route(self::BASE.'/{step}', name: 'app_municipal_request_new_step', requirements: self::IDS + ['step' => self::STEPS_REQUIREMENT], methods: ['GET', 'POST'])]
    public function stepAction(Request $request, string $municipalityId, string $eventId, string $step): Response
    {
        return $this->step($request, $this->context($request, $municipalityId, $eventId, $this->scope($municipalityId, $eventId)), $step);
    }

    protected function deposit(RequestFormContext $c, SubmitRequestCommand $command): SubmittedRequest
    {
        return $this->s->submission->submitByAgent($this->user(), (string) $c->municipalityId, $command, $c->draft->attemptKey());
    }

    private function scope(string $municipalityId, string $eventId, bool $requireOperational = true): AgentRequestScope
    {
        try {
            return $this->access->authorize($this->user(), $municipalityId, $eventId, $requireOperational);
        } catch (AgentRequestDenied $e) {
            throw new AccessDeniedHttpException('Action non autorisée.', $e);
        }
    }

    private function context(Request $request, string $municipalityId, string $eventId, AgentRequestScope $scope): RequestFormContext
    {
        $user = $this->user();
        $route = ['municipalityId' => $municipalityId, 'eventId' => $eventId];
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'new_request', 'Nouvelle demande');

        return new RequestFormContext(
            $scope->event,
            new PublicRequestDraft($request->getSession(), sprintf('agent.%s.%s.%s', $user->id()->toRfc4122(), $municipalityId, $eventId)),
            'agent_request_'.$municipalityId.'_'.$eventId,
            fn (PublicRequestStep $s): string => $this->generateUrl('app_municipal_request_new_step', $route + ['step' => $s->value]),
            fn (): string => $this->generateUrl('app_municipal_request_new_confirmation', $route),
            true,
            $user,
            $municipalityId,
            $shell + ['commune_name' => $scope->municipality->name(), 'new_request_url' => $this->generateUrl('app_municipal_request_new', ['municipalityId' => $municipalityId])],
        );
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
