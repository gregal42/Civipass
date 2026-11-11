<?php

declare(strict_types=1);

namespace App\Shell;

use App\Shared\Domain\ClosedEventAccess;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Traduit les erreurs métier de l'espace municipal en réponses HTTP, sans jamais révéler de motif :
 *  - ressource inaccessible (inexistante, étrangère, sans droit) → 404 identique pour tous les cas ;
 *  - action refusée à un utilisateur qui voit pourtant la ressource (rôle, statut de la commune, état de l'événement) → 403 ;
 *  - accès opérationnel à un événement clos (après contrôle des habilitations) → 410 neutre, sans aucune donnée (LOT 05.6.2) ;
 *  - conflit transactionnel (verrou, interblocage, unicité concurrente) → 409, « réessayez ».
 * Les erreurs de validation de formulaire sont rendues par les contrôleurs de formulaire (422 avec les saisies, 303 en
 * cas de succès) ; elles ne sont pas interceptées ici.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final readonly class ErrorResponseListener
{
    private const AREA = '/communes';

    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (self::AREA !== $path && !str_starts_with($path, self::AREA.'/')) {
            return;
        }
        $throwable = $event->getThrowable();
        if ($throwable instanceof ClosedEventAccess) {
            $event->setResponse(new Response($this->twig->render('municipal/error/event_closed.html.twig'), Response::HTTP_GONE, ['Cache-Control' => 'no-store, private']));
        } elseif ($throwable instanceof OperationNotAllowed) {
            $event->setResponse(new Response($this->twig->render('municipal/error/inaccessible.html.twig'), Response::HTTP_NOT_FOUND));
        } elseif ($throwable instanceof AccessDeniedHttpException) {
            $event->setResponse(new Response($this->twig->render('municipal/error/forbidden.html.twig'), Response::HTTP_FORBIDDEN));
        } elseif ($throwable instanceof OperationConflict) {
            $event->setResponse(new Response($this->twig->render('municipal/error/conflict.html.twig'), Response::HTTP_CONFLICT));
        }
    }
}
