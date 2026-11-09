<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\AgentRequestDenied;
use App\Request\Application\DirectIssuanceReader;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Confirmation d'une délivrance directe (DM-108), en lecture seule. L'accès est décidé par DirectIssuanceReader à CHAQUE affichage, en base :
 * l'agent émetteur seul, avec un rôle habilité à délivrer, pour une part de SA commune décidée par délivrance directe. Cette page ne
 * donne aucun accès à la consultation des demandes : MUNICIPALITY_ADMIN et EVENT_MANAGER n'y voient que leur propre délivrance.
 * 403 : rôle non habilité ; 404 uniforme : commune, dossier ou émetteur non concordants. Jamais mise en cache.
 */
final class DirectIssuanceController extends AbstractController
{
    public function __construct(
        private readonly DirectIssuanceReader $reader,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/delivrances/{requestId}', name: 'app_municipal_request_issued', requirements: ['municipalityId' => Requirement::UUID, 'requestId' => Requirement::UUID], methods: ['GET'])]
    public function issued(string $municipalityId, string $requestId): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'new_request', 'Autorisation délivrée');
        try {
            $confirmation = $this->reader->confirmation($user, $municipalityId, $requestId);
        } catch (AgentRequestDenied $e) {
            throw new AccessDeniedHttpException('Action non autorisée.', $e);
        }
        $route = ['municipalityId' => $municipalityId];
        $response = $this->render('municipal/request/issued.html.twig', $shell + [
            'confirmation' => $confirmation,
            'new_request_url' => $this->generateUrl('app_municipal_request_new', $route),
            'identity_url' => $confirmation->correctable ? $this->generateUrl('app_municipal_request_identity', $route + ['requestId' => $requestId]) : '',
            'request_url' => $confirmation->consultable ? $this->generateUrl('app_municipal_request', $route + ['requestId' => $requestId]) : '',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
