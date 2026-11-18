<?php

declare(strict_types=1);

namespace App\Shell;

use App\Event\Application\EventListReader;
use App\Event\Application\Read\EventListItem;
use App\Event\Domain\EventStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\MunicipalRequestReader;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Accueil de la commune active (LOT 08.1, DM-138) : événements en cours et à venir, demandes en attente, demandes récemment traitées (7 jours, 10 lignes) et accès
 * rapides. Jamais d'agrégat multi-communes ni de calcul de validité (coûteux). Chaque bloc est borné à la commune de l'URL, relue par MunicipalityContext (refus
 * uniforme) ; les blocs de demandes ne sont servis qu'aux INSTRUCTEURS (comme la liste des demandes).
 */
final class MunicipalHomeController extends AbstractController
{
    public const RECENT_DAYS = 7;
    public const RECENT_LIMIT = 10;

    public function __construct(
        private readonly ShellContextFactory $shell,
        private readonly MunicipalityContext $context,
        private readonly EventListReader $events,
        private readonly MunicipalRequestReader $requests,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/communes/{municipalityId}', name: 'app_municipal_home', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $municipalityId, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'home', 'Accueil');
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        $now = $this->clock->now();

        $current = [];
        $upcoming = [];
        foreach ($this->events->forMunicipality($user, $municipalityId) as $item) {
            if (EventStatus::Closed === $item->status) {
                continue;
            }
            if (EventStatus::Draft === $item->status || $item->startsAt > $now) {
                $upcoming[] = $item;
            } else {
                $current[] = $item;
            }
        }
        usort($upcoming, static fn (EventListItem $a, EventListItem $b): int => $a->startsAt <=> $b->startsAt);

        $summary = null;
        if ($membership->hasRole(MunicipalityRole::Instructor)) {
            $summary = $this->requests->homeSummary($user, $municipalityId, $now, self::RECENT_DAYS, self::RECENT_LIMIT);
        }
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }
        // Quick access : les rubriques de la navigation autorisées pour le rôle (hors accueil lui-même).
        $quick = array_values(array_filter($shell['nav_items'], static fn (array $i): bool => 'Accueil' !== $i['label']));

        $response = $this->render('municipal/home.html.twig', $shell + [
            'current_events' => $current,
            'upcoming_events' => $upcoming,
            'summary' => $summary,
            'quick_links' => $quick,
            'requests_url' => $this->generateUrl('app_municipal_requests', ['municipalityId' => $municipalityId]),
            'pending_url' => $this->generateUrl('app_municipal_requests', ['municipalityId' => $municipalityId, 'statut' => 'en-attente']),
            'events_url' => $this->generateUrl('app_municipal_events', ['municipalityId' => $municipalityId]),
        ]);
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
