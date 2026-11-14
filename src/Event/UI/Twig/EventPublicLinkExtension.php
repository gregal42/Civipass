<?php

declare(strict_types=1);

namespace App\Event\UI\Twig;

use App\Permit\Application\QrCodeRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Fonction `event_public_url(référence)` : adresse complète du dépôt public d'un événement (`/demandes/{référence}`), à communiquer aux citoyens.
 * Le domaine vient UNIQUEMENT de la configuration (`DEFAULT_URI`), jamais d'un en-tête Host ou X-Forwarded-* : comme pour tous les liens diffusés
 * (QR, e-mails), une requête forgée ne peut pas faire afficher une adresse qui pointe ailleurs.
 */
final readonly class EventPublicLinkExtension
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private QrCodeRenderer $qr,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
    ) {
    }

    #[AsTwigFunction('event_public_url')]
    public function url(string $publicReference): string
    {
        return rtrim($this->baseUrl, '/').$this->urls->generate('app_public_request_start', ['eventReference' => $publicReference], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /** SVG du QR code de ce lien (affichage ou impression en mairie) : le contenu est l'adresse publique, sans aucune donnée personnelle. */
    #[AsTwigFunction('event_public_qr', isSafe: ['html'])]
    public function qr(string $publicReference): string
    {
        return $this->qr->svg($this->url($publicReference));
    }
}
