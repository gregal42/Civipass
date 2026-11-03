<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\EventListReader;
use App\Event\Application\Read\EventListQuery;
use App\Event\Domain\EventStatus;
use App\Identity\Domain\User;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Liste des événements d'une commune : recherche, statut, type, tri et pagination côté serveur. L'identifiant de
 * commune de l'URL est vérifié par le contexte municipal (membership effectif) ; les événements viennent d'une lecture
 * SQL bornée à ce périmètre. Les paramètres d'URL invalides sont ignorés (valeurs par défaut), jamais exécutés.
 */
final class EventListController extends AbstractController
{
    public function __construct(
        private readonly EventListReader $events,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements', name: 'app_municipal_events', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $municipalityId, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Événements');

        $types = $this->events->typeOptions();
        // Paramètres lus bruts : une valeur absente, tableau ou hors limites vaut « aucun filtre », jamais une erreur.
        $raw = $request->query->all();
        $text = static fn (string $key): string => \is_string($raw[$key] ?? null) ? $raw[$key] : '';
        $typeCode = $text('type');
        $pageNumber = $text('page');
        $query = new EventListQuery(
            $text('q'),
            EventStatus::tryFrom(strtoupper($text('statut'))),
            \in_array($typeCode, array_map(static fn ($t): string => $t->code, $types), true) ? $typeCode : null,
            $text('tri'),
            ctype_digit($pageNumber) && \strlen($pageNumber) <= 6 ? max(1, (int) $pageNumber) : 1,
        );
        $page = $this->events->search($user, $municipalityId, $query);
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }

        $params = array_filter([
            'q' => $query->search,
            'statut' => $query->status?->value ? strtolower($query->status->value) : null,
            'type' => $query->typeCode,
            'tri' => 'asc' === $query->direction ? 'asc' : null,
        ], static fn (?string $v): bool => null !== $v);
        $route = static fn (array $extra = []) => ['municipalityId' => $municipalityId] + $extra;

        return $this->render('municipal/event/list.html.twig', $shell + [
            'page' => $page,
            'query' => $query,
            'filters' => $params,
            'type_options' => $types,
            'create_url' => $this->generateUrl('app_municipal_event_new', $route()),
            'list_url' => $this->generateUrl('app_municipal_events', $route()),
            'page_pattern' => $this->generateUrl('app_municipal_events', $route($params + ['page' => '__PAGE__'])),
            'sort_url' => $this->generateUrl('app_municipal_events', $route(array_diff_key($params, ['tri' => true]) + ('asc' === $query->direction ? [] : ['tri' => 'asc']))),
            'reset_url' => $this->generateUrl('app_municipal_events', $route()),
            'chip_urls' => array_map(
                fn (string $key): string => $this->generateUrl('app_municipal_events', $route(array_diff_key($params, [$key => true]))),
                array_combine(array_keys($params), array_keys($params)),
            ),
        ]);
    }
}
