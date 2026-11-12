<?php

declare(strict_types=1);

namespace App\Retention\UI\Http;

use App\Identity\Domain\User;
use App\Retention\Application\EventDeletionService;
use App\Retention\Application\RetentionRegistryReader;
use App\Shared\Domain\DomainRuleViolation;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Registre de conservation et suppression d'un événement (LOT 05.6.4) : préfixe `/communes/{m}/retention/…` RÉSERVÉ, hors barrière de clôture. Droit relu AVANT le jeton
 * CSRF ; tout refus est le 404 uniforme (jamais « clos »). `no-store`.
 */
final class RetentionController extends AbstractController
{
    private const IDS = ['municipalityId' => Requirement::UUID];

    public function __construct(private readonly RetentionRegistryReader $registry, private readonly EventDeletionService $deletion, private readonly ShellContextFactory $shell)
    {
    }

    #[Route('/communes/{municipalityId}/retention', name: 'app_retention_index', requirements: self::IDS, methods: ['GET'])]
    public function index(string $municipalityId): Response
    {
        $user = $this->user();
        $data = $this->registry->forAdministrator($user, $municipalityId);
        $response = $this->render('retention/index.html.twig', array_merge($this->shell->forMunicipality($user, $municipalityId, 'archives', 'Conservation'), ['page_title' => 'Conservation', 'municipalityId' => $municipalityId], $data));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/communes/{municipalityId}/retention/evenements/{eventId}/supprimer', name: 'app_retention_event_delete', requirements: self::IDS + ['eventId' => Requirement::UUID], methods: ['POST'])]
    public function deleteEvent(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->user();
        $this->deletion->blockers($user, $municipalityId, $eventId); // droit AVANT le jeton CSRF : refus uniforme 404
        $post = $request->request->all();
        $token = \is_string($post['_token'] ?? null) ? $post['_token'] : '';
        if (!$this->isCsrfTokenValid('retention_delete_'.$eventId, $token)) {
            $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été supprimé : validez à nouveau.');
        } else {
            try {
                $this->deletion->delete($user, $municipalityId, $eventId, \is_string($post['reference'] ?? null) ? $post['reference'] : '');
                $this->addFlash('success', 'L’événement est supprimé définitivement.');
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_retention_index', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER);
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
