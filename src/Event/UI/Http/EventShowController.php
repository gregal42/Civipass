<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\ClosureStatisticsReader;
use App\Event\Application\EventDetailReader;
use App\Identity\Domain\User;
use App\Shell\CommuneSelectionController;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Fiche d'un événement vue par la commune de l'URL. Commune et événement sont deux identifiants non fiables : le
 * lecteur exige un membership effectif ET un lien de la commune avec l'événement ; tout autre cas produit le même
 * refus (404 uniforme). Les actions (Modifier, Activer…) sont décidées par la politique d'accès ; celles qui ne sont pas
 * encore branchées en HTTP (incréments suivants) sont présentées comme indisponibles, jamais simulées.
 */
final class EventShowController extends AbstractController
{
    public function __construct(
        private readonly EventDetailReader $details,
        private readonly ShellContextFactory $shell,
        private readonly EventViewFactory $views,
        private readonly ClosureStatisticsReader $statistics,
    ) {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}', name: 'app_municipal_event', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Événement');
        $detail = $this->details->forEvent($user, $municipalityId, $eventId);
        if ($request->hasSession()) {
            $request->getSession()->set(CommuneSelectionController::SESSION_KEY, $municipalityId);
        }

        return $this->render('municipal/event/show.html.twig', $this->views->page($shell, $detail, $municipalityId, $detail->name, extra: ['closure_statistics' => $this->statistics->forViewer($user, $municipalityId, $eventId)]));
    }
}
