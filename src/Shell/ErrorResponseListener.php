<?php

declare(strict_types=1);

namespace App\Shell;

use App\Identity\Domain\User;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Domain\ClosedEventAccess;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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

    public function __construct(
        private Environment $twig,
        private ?Security $security = null,
        private ?ShellContextFactory $shell = null,
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (self::AREA !== $path && !str_starts_with($path, self::AREA.'/')) {
            return;
        }
        $throwable = $event->getThrowable();
        if ($throwable instanceof ClosedEventAccess) {
            $event->setResponse(new Response($this->twig->render('municipal/error/event_closed.html.twig', ['archives_href' => $this->archivesHref($path)]), Response::HTTP_GONE, ['Cache-Control' => 'no-store, private']));
        } elseif ($throwable instanceof OperationNotAllowed) {
            $event->setResponse(new Response($this->twig->render('municipal/error/inaccessible.html.twig'), Response::HTTP_NOT_FOUND));
        } elseif ($throwable instanceof AccessDeniedHttpException) {
            $event->setResponse(new Response($this->twig->render('municipal/error/forbidden.html.twig'), Response::HTTP_FORBIDDEN));
        } elseif ($throwable instanceof OperationConflict) {
            $event->setResponse(new Response($this->twig->render('municipal/error/conflict.html.twig'), Response::HTTP_CONFLICT));
        }
    }

    /**
     * Lien vers les archives de la commune de l'URL, seulement pour un membre actif qui y a le rôle d'administrateur communal ou de gestionnaire
     * d'événements (même règle d'affichage que le menu « Archives » ; l'accès réel reste décidé par ArchiveAccessPolicy à chaque requête).
     */
    private function archivesHref(string $path): ?string
    {
        $user = $this->security?->getUser();
        if (null === $this->shell || null === $this->urls || !$user instanceof User || 1 !== preg_match('#^/communes/([0-9a-fA-F-]{36})(?:/|$)#', $path, $m)) {
            return null;
        }
        $municipalityId = strtolower($m[1]);
        foreach ($this->shell->readableMemberships($user) as $membership) {
            if ($membership->municipality()->id()->toRfc4122() === $municipalityId && ($membership->hasRole(MunicipalityRole::MunicipalityAdmin) || $membership->hasRole(MunicipalityRole::EventManager))) {
                return $this->urls->generate('app_archive_index', ['municipalityId' => $municipalityId]);
            }
        }

        return null;
    }
}
