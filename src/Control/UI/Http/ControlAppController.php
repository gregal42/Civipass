<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

use App\Control\Application\ControlSettings;
use App\Permit\Application\VerificationUrlFactory;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PWA de contrôle hors connexion (LOT 09, DM-129, DT-056) : coquille de l'application, manifeste, Service Worker et sonde de connectivité. Aucune donnée
 * personnelle ni donnée de commune : tout ce qui est servi ici est identique pour tous et peut donc être conservé par le Service Worker. Interrupteur
 * `OFFLINE_CONTROL_ENABLED` (0 par défaut) : coupé, TOUTES les routes répondent la même 404 qu'une route inconnue. Pare-feu `control_pwa` : aucune session.
 */
final class ControlAppController
{
    public function __construct(
        private readonly ControlSettings $settings,
        private readonly VerificationUrlFactory $urls,
        private readonly AssetMapperInterface $assets,
        private readonly \Twig\Environment $twig,
    ) {
    }

    #[Route('/controle/', name: 'app_control_app', methods: ['GET'])]
    public function app(): Response
    {
        $this->guard();

        return new Response($this->twig->render('control/app.html.twig', [
            'verification_base' => $this->urls->absolute('/'),
            'icon_192' => $this->assetPath('images/control-icon-192.png'),
        ]));
    }

    #[Route('/controle/manifest.webmanifest', name: 'app_control_manifest', methods: ['GET'])]
    public function manifest(): Response
    {
        $this->guard();

        return new JsonResponse([
            'name' => 'Civipass Contrôle',
            'short_name' => 'Contrôle',
            'description' => 'Contrôle des laissez-passer Civipass, y compris sans connexion.',
            'start_url' => '/controle/',
            'scope' => '/controle/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'lang' => 'fr',
            'background_color' => '#1d3f73',
            'theme_color' => '#1d3f73',
            'icons' => [
                ['src' => $this->assetPath('images/control-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->assetPath('images/control-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], Response::HTTP_OK, ['Content-Type' => 'application/manifest+json']);
    }

    #[Route('/controle/sw.js', name: 'app_control_service_worker', methods: ['GET'])]
    public function serviceWorker(): Response
    {
        $this->guard();
        $precache = $this->precacheList();
        $version = substr(hash('sha256', implode("\n", $precache)), 0, 16);
        $body = $this->twig->render('control/sw.js.twig', ['version' => $version, 'precache' => $precache]);

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/javascript; charset=utf-8', 'Service-Worker-Allowed' => '/controle/']);
    }

    /** Sonde de connectivité ET d'heure serveur (même origine, sans donnée) : permet de choisir entre le contrôle en ligne et le repli hors ligne. */
    #[Route('/controle/api/ping', name: 'app_control_ping', methods: ['GET'])]
    public function ping(): Response
    {
        $this->guard();

        return new JsonResponse(['t' => time()]);
    }

    private function guard(): void
    {
        if (!$this->settings->enabled) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Fichiers de la coquille à conserver hors ligne : adresses PUBLIQUES (avec empreinte) de tout ce que charge la page.
     *
     * @return list<string>
     */
    private function precacheList(): array
    {
        $list = ['/controle/', '/controle/manifest.webmanifest'];
        foreach (['styles/civipass/tokens.css', 'styles/civipass/civipass.css', 'styles/civipass-app.css', 'styles/controle.css', 'vendor/jsqr/jsQR.js', 'images/control-icon-192.png', 'images/control-icon-512.png'] as $logical) {
            $list[] = $this->assetPath($logical);
        }
        $dir = \dirname(__DIR__, 4).'/assets/controle';
        $modules = glob($dir.'/*.js') ?: [];
        sort($modules);
        foreach ($modules as $file) {
            $list[] = $this->assetPath('controle/'.basename($file));
        }

        return array_values(array_unique($list));
    }

    private function assetPath(string $logicalPath): string
    {
        return $this->assets->getPublicPath($logicalPath) ?? throw new \LogicException(sprintf('Ressource introuvable : %s', $logicalPath));
    }
}
