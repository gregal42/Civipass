<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\ClosedEventResolver;
use App\Event\Domain\EventStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\ClosedEventAccess;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Barrière HTTP centrale de la clôture (LOT 05.6.2, DM-120) : aucune route d'exploitation d'un dossier ne répond sur un événement clos, quelle que soit la
 * méthode, le rôle ou le chemin (URL directe, téléchargement, POST forgé). Ordre de sécurité :
 *  1. route non gardée → rien (les routes de commune sans identifiant de dossier sont filtrées par les lecteurs) ;
 *  2. habilitation d'abord : un utilisateur sans appartenance effective à la commune de l'URL reçoit le refus UNIFORME habituel, jamais « clos » ;
 *  3. dossier cherché par sa PORTION COMMUNALE : absent ou étranger → refus uniforme du contrôleur ;
 *  4. événement clos → `ClosedEventAccess` (410 neutre, sans donnée).
 * Reste connu : un membre de la commune dépourvu du rôle requis par la route apprend qu'un dossier de SA commune est clos (identifiant UUID v7
 * non devinable ; aucune fuite vers une autre commune ni vers un non-membre).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 100)]
final class ClosedEventBarrier
{
    /** @var array<string, RouteClosure> */
    private array $cache = [];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly ClosedEventRouteClassifier $classifier,
        private readonly TokenStorageInterface $tokens,
        private readonly MunicipalityContext $context,
        private readonly ClosedEventResolver $resolver,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $name = $request->attributes->get('_route');
        if (!\is_string($name) || RouteClosure::Guarded !== $this->closure($name)) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        $municipalityId = $request->attributes->get('municipalityId');
        $requestId = $request->attributes->get('requestId');
        if (!$user instanceof User || !\is_string($municipalityId) || !\is_string($requestId) || !Uuid::isValid($municipalityId) || !Uuid::isValid($requestId)) {
            return;
        }
        try {
            $this->context->requireMembership($user, $municipalityId);
        } catch (OperationNotAllowed) {
            return; // refus uniforme du contrôleur
        }
        $status = $this->resolver->eventStatusOfDossier(Uuid::fromString($municipalityId), Uuid::fromString($requestId));
        if (EventStatus::Closed === $status) {
            throw ClosedEventAccess::create();
        }
    }

    private function closure(string $routeName): RouteClosure
    {
        if (!isset($this->cache[$routeName])) {
            $route = $this->router->getRouteCollection()->get($routeName);
            $this->cache[$routeName] = null === $route ? RouteClosure::Unclassified : $this->classifier->classify($route->getPath());
        }

        return $this->cache[$routeName];
    }
}
