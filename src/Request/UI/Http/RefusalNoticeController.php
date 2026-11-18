<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\User;
use App\Request\Application\RefusalNoticeService;
use App\Request\Domain\ManualChannel;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * « Refus à communiquer » d'une commune (LOT 08.5, DM-136) : demandeurs refusés qui n'ont pas pu être informés par e-mail. Instructeurs et administrateurs de la commune
 * de l'URL ; refus uniforme (404) pour une commune étrangère ou un rôle insuffisant ; marquage par POST + jeton CSRF propre à la ligne. Aucune adresse n'est affichée.
 */
final class RefusalNoticeController extends AbstractController
{
    public function __construct(
        private readonly RefusalNoticeService $service,
        private readonly ShellContextFactory $shell,
    ) {
    }

    #[Route('/communes/{municipalityId}/refus-a-communiquer', name: 'app_municipal_refusals', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function list(string $municipalityId): Response
    {
        $user = $this->user();
        try {
            $shell = $this->shell->forMunicipality($user, $municipalityId, 'refusals', 'Refus à communiquer');
            $rows = $this->service->list($user, $municipalityId);
        } catch (OperationNotAllowed) {
            throw new NotFoundHttpException();
        }
        $response = $this->render('municipal/request/refusals.html.twig', $shell + [
            'rows' => $rows,
            'channels' => ManualChannel::cases(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/communes/{municipalityId}/refus-a-communiquer/{noticeId}/informe', name: 'app_municipal_refusal_informed', requirements: ['municipalityId' => Requirement::UUID, 'noticeId' => Requirement::UUID], methods: ['POST'])]
    public function informed(string $municipalityId, string $noticeId, Request $request): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('refusal_informed_'.$noticeId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré. Recommencez.');
        } else {
            $channel = ManualChannel::tryFrom((string) $request->request->get('channel'));
            try {
                if (null === $channel) {
                    throw new DomainRuleViolation('Choisissez le moyen par lequel le demandeur a été informé.');
                }
                $this->service->markInformed($user, $municipalityId, $noticeId, $channel);
                $this->addFlash('success', 'Le demandeur est marqué comme informé.');
            } catch (OperationNotAllowed) {
                throw new NotFoundHttpException();
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_municipal_refusals', ['municipalityId' => $municipalityId], Response::HTTP_SEE_OTHER);
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
