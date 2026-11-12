<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\Dormancy\EventDormancyBanner;
use App\Event\Application\Dormancy\EventDormancySnoozeService;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/** Report motivé de l'alerte de dormance (LOT 05.6.5) : droit relu AVANT le jeton CSRF (refus uniforme 404), aucune action sur le statut de l'événement. */
final class EventDormancyController extends AbstractController
{
    public function __construct(private readonly EventDormancySnoozeService $snooze, private readonly EventDormancyBanner $banner)
    {
    }

    #[Route('/communes/{municipalityId}/evenements/{eventId}/dormance/reporter', name: 'app_event_dormancy_snooze', requirements: ['municipalityId' => Requirement::UUID, 'eventId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(string $municipalityId, string $eventId, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $this->snooze->authorized($user, $municipalityId, $eventId); // droit AVANT tout (jeton CSRF compris)
        if (null === $this->banner->forViewer($user, $municipalityId, $eventId)) {
            throw new NotFoundHttpException();
        }
        $post = $request->request->all();
        $token = \is_string($post['_token'] ?? null) ? $post['_token'] : '';
        if (!$this->isCsrfTokenValid('event_dormancy_snooze_'.$eventId, $token)) {
            $this->addFlash('error', 'Le formulaire a expiré. Rien n’a été enregistré : validez à nouveau.');
        } else {
            try {
                $until = $this->snooze->snooze($user, $municipalityId, $eventId, (int) ($post['days'] ?? 0), \is_string($post['reason'] ?? null) ? $post['reason'] : '');
                $this->addFlash('success', sprintf('Le rappel est reporté jusqu’au %s.', $until->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y')));
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_municipal_event', ['municipalityId' => $municipalityId, 'eventId' => $eventId], Response::HTTP_SEE_OTHER);
    }
}
