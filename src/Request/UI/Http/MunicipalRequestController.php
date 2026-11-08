<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\InstructorRoleRequired;
use App\Request\Application\MunicipalRequestReader;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Domain\MunicipalRequestStatus;
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
 * Consultation municipale des demandes, en lecture seule : liste d'un événement et fiche d'une demande. Commune, événement et
 * demande sont des identifiants non fiables, revérifiés par MunicipalRequestReader (membership, rôle INSTRUCTOR, portion
 * communale) ; aucune écriture n'est possible ici. Les paramètres d'URL invalides sont ignorés, jamais exécutés.
 */
final class MunicipalRequestController extends AbstractController
{
    /** Valeurs d'URL du filtre de statut (statut de la PORTION de la commune, jamais un statut global). */
    public const STATUS_FILTERS = ['en-attente' => MunicipalRequestStatus::Pending, 'approuvees' => MunicipalRequestStatus::Approved, 'refusees' => MunicipalRequestStatus::Refused];

    public function __construct(
        private readonly MunicipalRequestReader $requests,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/demandes', name: 'app_municipal_requests', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function list(string $municipalityId, Request $request): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Demandes');
        $raw = $request->query->all();
        $text = static fn (string $key): string => \is_string($raw[$key] ?? null) ? $raw[$key] : '';
        $pageNumber = $text('page');
        $eventId = $text('evenement');
        $query = new MunicipalRequestQuery(
            mb_substr(trim($text('q')), 0, 100),
            '' !== $text('categorie') ? $text('categorie') : null,
            ctype_digit($pageNumber) && \strlen($pageNumber) <= 6 ? max(1, (int) $pageNumber) : 1,
            self::STATUS_FILTERS[$text('statut')] ?? null,
        );
        try {
            $listing = $this->requests->listing($user, $municipalityId, Uuid::isValid($eventId) ? Uuid::fromString($eventId)->toRfc4122() : null, $query);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }

        $event = $listing->event;
        $filters = array_filter([
            'evenement' => $event?->eventId,
            'q' => $query->search,
            'categorie' => $listing->page && null !== $query->categoryCode && $this->hasCategory($listing->categories, $query->categoryCode) ? $query->categoryCode : null,
            'statut' => null === $query->status ? null : array_search($query->status, self::STATUS_FILTERS, true),
        ], static fn (?string $v): bool => null !== $v && '' !== $v);
        $route = static fn (array $extra = []) => ['municipalityId' => $municipalityId] + $extra;

        return $this->render('municipal/request/list.html.twig', $shell + [
            'listing' => $listing,
            'query' => $query,
            'filters' => $filters,
            'list_url' => $this->generateUrl('app_municipal_requests', $route()),
            'event_urls' => array_column(array_map(fn ($e): array => [$e->eventId, $this->generateUrl('app_municipal_requests', $route(['evenement' => $e->eventId]))], $listing->events), 1, 0),
            'page_pattern' => $this->generateUrl('app_municipal_requests', $route($filters + ['page' => '__PAGE__'])),
            'reset_url' => $this->generateUrl('app_municipal_requests', $route(null === $event ? [] : ['evenement' => $event->eventId])),
            'chip_urls' => array_map(
                fn (string $key): string => $this->generateUrl('app_municipal_requests', $route(array_diff_key($filters, [$key => true]))),
                array_combine(array_keys($filters), array_keys($filters)),
            ),
            'status_tabs' => $this->statusTabs($route, $filters, $query),
            'detail_urls' => array_column(array_map(fn ($r): array => [$r->requestId, $this->generateUrl('app_municipal_request', $route(['requestId' => $r->requestId]))], $listing->page->items ?? []), 1, 0),
        ]);
    }

    #[Route('/communes/{municipalityId}/demandes/{requestId}', name: 'app_municipal_request', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET'])]
    public function show(string $municipalityId, string $requestId, Request $request): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'requests', 'Demande');
        try {
            $page = $this->requests->decisionPage($user, $municipalityId, $requestId);
        } catch (InstructorRoleRequired) {
            throw new AccessDeniedHttpException('Action non autorisée.');
        }
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
        $route = ['municipalityId' => $municipalityId];
        $detail = $page->detail;

        return $this->render('municipal/request/show.html.twig', $shell + [
            'page_title' => 'Demande '.$detail->reference,
            'detail' => $detail,
            'can_decide' => $page->canDecide(),
            'unavailable_reason' => $page->unavailableReason,
            'approve_url' => $this->generateUrl('app_municipal_request_approve', $route + ['requestId' => $requestId]),
            'refuse_url' => $this->generateUrl('app_municipal_request_refuse', $route + ['requestId' => $requestId]),
            'requests_url' => $this->generateUrl('app_municipal_requests', $route),
            'list_url' => $this->generateUrl('app_municipal_requests', $route + ['evenement' => $detail->eventId]),
            'download_urls' => array_combine(
                array_map(static fn ($a): string => $a->id, $detail->attachments),
                array_map(fn ($a): string => $this->generateUrl('app_municipal_request_attachment', $route + ['requestId' => $requestId, 'attachmentId' => $a->id]), $detail->attachments),
            ),
        ]);
    }

    /**
     * Onglets de statut (liens, pas de rechargement caché) : conservent recherche, événement et catégorie, repartent à la page 1.
     *
     * @param callable(array<string, string>): array<string, string> $route
     * @param array<string, string>                                  $filters
     *
     * @return list<array{label: string, href: string, current: bool}>
     */
    private function statusTabs(callable $route, array $filters, MunicipalRequestQuery $query): array
    {
        $tabs = [];
        foreach ([['Toutes', null, null], ['En attente', 'en-attente', MunicipalRequestStatus::Pending], ['Approuvées', 'approuvees', MunicipalRequestStatus::Approved], ['Refusées', 'refusees', MunicipalRequestStatus::Refused]] as [$label, $value, $status]) {
            $params = array_diff_key($filters, ['statut' => true]) + (null === $value ? [] : ['statut' => $value]);
            $tabs[] = ['label' => $label, 'href' => $this->generateUrl('app_municipal_requests', $route($params)), 'current' => $query->status === $status];
        }

        return $tabs;
    }

    /**
     * @param list<array{code: string, label: string}> $categories
     */
    private function hasCategory(array $categories, string $code): bool
    {
        foreach ($categories as $category) {
            if ($category['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
