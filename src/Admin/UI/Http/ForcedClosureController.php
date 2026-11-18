<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Admin\Application\ForcedEventClosure;
use App\Event\Domain\EventRepository;
use App\Event\Domain\PublicReference;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Clôture EXCEPTIONNELLE d'un événement par un opérateur CWS habilité (LOT 08.6, DM-065, DM-139). Sous `/admin/cws/` : super-administrateur en authentification
 * COMPLÈTE (security.yaml), puis habilitation `CWS_FORCE_CLOSE_EVENT` relue en base par le service. Un opérateur non habilité obtient un 404 uniforme (la fonction
 * n'existe pas pour lui). POST + jeton CSRF propre à l'événement ; les pages ne sont jamais mises en cache ; aucun mot de passe ni motif n'est renvoyé au navigateur.
 */
#[Route('/admin/cws/evenements')]
final class ForcedClosureController extends AbstractController
{
    public function __construct(
        private readonly ForcedEventClosure $closure,
        private readonly EventRepository $events,
    ) {
    }

    #[Route('/cloture', name: 'admin_cws_forced_closure_find', methods: ['GET', 'POST'])]
    public function find(Request $request): Response
    {
        $operator = $this->operator();
        $this->assertStillGranted($operator); // sans habilitation, la fonction n'existe pas : 404 uniforme dès la page de recherche
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cws_forced_closure_find', (string) $request->request->get('_token'))) {
                $error = 'Le formulaire a expiré. Recommencez.';
            } else {
                $event = null;
                try {
                    $event = $this->events->findByPublicReference(PublicReference::fromString(trim((string) $request->request->get('reference'))));
                } catch (DomainRuleViolation) {
                    // référence mal formée : même message que « inconnue »
                }
                try {
                    if (null === $event) {
                        throw new OperationNotAllowed('inconnu');
                    }
                    $this->closure->preview($operator, $event->id()->toRfc4122());

                    return $this->redirectToRoute('admin_cws_forced_closure', ['eventId' => $event->id()->toRfc4122()], Response::HTTP_SEE_OTHER);
                } catch (OperationNotAllowed $e) {
                    $this->assertStillGranted($operator);
                    $error = 'Événement introuvable ou déjà clôturé.';
                }
            }
        }

        return $this->page('admin/cws/forced_closure_find.html.twig', ['error' => $error]);
    }

    #[Route('/{eventId}/cloture', name: 'admin_cws_forced_closure', requirements: ['eventId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(string $eventId, Request $request): Response
    {
        $operator = $this->operator();
        try {
            $preview = $this->closure->preview($operator, $eventId);
        } catch (OperationNotAllowed) {
            throw $this->createNotFoundException();
        }
        $error = null;
        $tokenId = 'cws_forced_closure_'.$eventId;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                $error = 'Le formulaire a expiré. Recommencez.';
            } else {
                try {
                    $this->closure->close($operator, $eventId, (string) $request->request->get('password'), (string) $request->request->get('reason'), (string) $request->request->get('reference'));
                    $this->addFlash('success', sprintf('L’événement %s est clôturé. Les administrateurs communaux concernés sont informés.', $preview->reference));

                    return $this->redirectToRoute('admin_cws_forced_closure_find', status: Response::HTTP_SEE_OTHER);
                } catch (OperationNotAllowed) {
                    throw $this->createNotFoundException();
                } catch (DomainRuleViolation $e) {
                    $error = $e->getMessage();
                } catch (OperationConflict) {
                    $error = 'Un autre traitement est en cours sur cet événement. Réessayez dans un instant ; rien n’a été modifié.';
                }
            }
        }

        // Ni mot de passe ni motif n'est renvoyé dans la page : l'opérateur les ressaisit après une erreur.
        return $this->page('admin/cws/forced_closure.html.twig', ['preview' => $preview, 'error' => $error, 'token_id' => $tokenId, 'reason_min' => ForcedEventClosure::REASON_MIN_LENGTH, 'reason_max' => ForcedEventClosure::REASON_MAX_LENGTH]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $context): Response
    {
        $response = $this->render($template, $context);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function operator(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }

    /** Un opérateur sans habilitation ne doit rien apprendre : 404 uniforme. */
    private function assertStillGranted(User $operator): void
    {
        try {
            $this->closure->assertGranted($operator);
        } catch (OperationNotAllowed) {
            throw $this->createNotFoundException();
        }
    }
}
