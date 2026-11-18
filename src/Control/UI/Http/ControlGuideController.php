<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use App\Control\Application\ControlSettings;
use App\Control\Application\ControlUrlFactory;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Shell\ShellContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Entrée « Contrôle » de l'espace communal (LOT 08.2, DM-138) : consigne de terrain en lecture, pour tout membre actif de la commune de l'URL (relu par
 * MunicipalityContext : 404 uniforme pour une commune étrangère). AUCUNE donnée de laissez-passer, aucun code d'activation, aucun jeton. N'active rien : elle
 * indique seulement l'état des interrupteurs. Le TEXTE est provisoire : à valider par le métier avant publication (DM-138).
 */
final class ControlGuideController extends AbstractController
{
    public function __construct(
        private readonly ShellContextFactory $shell,
        private readonly MunicipalityContext $context,
        private readonly ControlSettings $settings,
        private readonly ControlUrlFactory $urls,
        #[Autowire('%env(bool:PUBLIC_VERIFICATION_ENABLED)%')] private readonly bool $onlineEnabled,
    ) {
    }

    #[Route('/communes/{municipalityId}/controle', name: 'app_control_guide', requirements: ['municipalityId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $municipalityId): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $shell = $this->shell->forMunicipality($user, $municipalityId, 'control', 'Contrôle');
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);

        $response = $this->render('municipal/control/guide.html.twig', $shell + [
            'online_enabled' => $this->onlineEnabled,
            'offline_enabled' => $this->settings->enabled,
            'is_admin' => $membership->hasRole(MunicipalityRole::MunicipalityAdmin),
            'pwa_url' => $this->settings->enabled ? $this->urls->app() : null,
            'hard_max_hours' => intdiv($this->settings->hardMaxSeconds, 3600),
            'warn_hours' => intdiv($this->settings->defaultWarnSeconds, 3600),
            'events_url' => $this->generateUrl('app_municipal_events', ['municipalityId' => $municipalityId]),
        ]);
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
