<?php

declare(strict_types=1);

namespace App\Archive\UI\Http;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Archive\Application\StatisticsCoordinationService;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Habilitation de coordination statistique (L5-36, arbitrage A1) : page de gestion du `MUNICIPALITY_ADMIN` de la commune ORGANISATRICE (famille de routes « événement »,
 * aucune donnée de dossier). DISTINCTE de la désignation d'archive. Refus de droit : 404 uniforme.
 */
final class StatisticsCoordinationController extends AbstractController
{
    private const BASE = '/communes/{municipalityId}/evenements/{eventId}/statistiques/habilitations';
    private const IDS = ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID];

    public function __construct(
        private readonly StatisticsCoordinationService $coordination,
        private readonly ShellContextFactory $shell,
        private readonly ArchiveAccessPolicy $policy,
    ) {
    }

    #[Route(self::BASE, name: 'app_event_statistics_coordination', requirements: self::IDS, methods: ['GET'])]
    public function index(string $municipalityId, string $eventId): Response
    {
        $user = $this->user();
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'events', 'Coordination statistique');
        $data = $this->coordination->forEvent($user, $municipalityId, $eventId);
        $response = $this->render('archive/coordination.html.twig', array_merge($shell, ['page_title' => 'Coordination statistique', 'municipalityId' => $municipalityId, 'eventId' => $eventId], $data));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route(self::BASE, name: 'app_event_statistics_coordination_grant', requirements: self::IDS, methods: ['POST'])]
    public function grant(string $municipalityId, string $eventId, Request $request): Response
    {
        return $this->act($municipalityId, $eventId, $request, 'stats_coordination_grant_'.$eventId, 'L’habilitation de coordination est accordée.', fn (User $user, array $post) => $this->coordination->grant($user, $municipalityId, $eventId, $this->text($post['user'] ?? '')));
    }

    #[Route(self::BASE.'/{grantId}/revoquer', name: 'app_event_statistics_coordination_revoke', requirements: self::IDS + ['grantId' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $municipalityId, string $eventId, string $grantId, Request $request): Response
    {
        return $this->act($municipalityId, $eventId, $request, 'stats_coordination_revoke_'.$grantId, 'L’habilitation de coordination est révoquée.', fn (User $user, array $post) => $this->coordination->revoke($user, $municipalityId, $grantId));
    }

    /** @param callable(User, array<string, mixed>): mixed $operation */
    private function act(string $municipalityId, string $eventId, Request $request, string $tokenId, string $success, callable $operation): Response
    {
        $user = $this->user();
        $this->policy->administrator($user, $municipalityId, write: false); // droit AVANT le jeton CSRF : refus uniforme
        $post = $request->request->all();
        if (!$this->isCsrfTokenValid($tokenId, $this->text($post['_token'] ?? ''))) {
            $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.');
        } else {
            try {
                $operation($user, $post);
                $this->addFlash('success', $success);
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (OperationConflict) {
                $this->addFlash('error', 'Un autre traitement est en cours : rien n’a été enregistré. Vous pouvez réessayer.');
            }
        }

        return $this->redirectToRoute('app_event_statistics_coordination', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
