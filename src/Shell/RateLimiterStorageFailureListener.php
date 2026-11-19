<?php

declare(strict_types=1);

namespace App\Shell;

use App\Permit\Infrastructure\RateLimiter\RateLimiterStorageFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Panne du stockage des compteurs de limitation (SEC-014, LOT 11.1) : le service répond 503 neutre et la panne est journalisée au niveau erreur. Jamais de
 * contournement silencieux : une protection qui ne peut plus compter (disque plein, `var/cache` devenu inaccessible après une mise à jour lancée en root)
 * refuse de servir plutôt que de laisser des essais illimités sans le dire. Le message ne révèle rien (ni chemin, ni limiteur, ni compte).
 */
final readonly class RateLimiterStorageFailureListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onException(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        while (null !== $failure && !$failure instanceof RateLimiterStorageFailure) {
            $failure = $failure->getPrevious();
        }
        if (null === $failure) {
            return;
        }
        $this->logger->error('Compteurs de limitation indisponibles : requête refusée (503). Vérifier l\'espace disque et les droits de var/cache.');
        $response = new Response(
            '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Service momentanément indisponible</title></head>'
            .'<body><h1>Service momentanément indisponible</h1><p>Réessayez dans quelques minutes.</p></body></html>',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private', 'Retry-After' => '60'],
        );
        $event->setResponse($response);
    }
}
