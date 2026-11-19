<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Shared\UI\Http\ClientNetworkKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limitation du dépôt public (SEC-003, LOT 11.1) : empêche d'inonder un événement de demandes et d'utiliser la plateforme comme relais d'e-mails.
 *
 * - Dépôts : par adresse réseau (IPv6 : /64) ET par événement, sur une heure (`PUBLIC_REQUEST_SUBMIT_LIMIT`, 15 par défaut). Un citoyen dépose 1 à 3 demandes
 *   (proches, véhicules) ; 15 laisse passer un foyer, un point d'accueil, un réseau mobile partagé ou une mairie sans être atteint, tout en bornant l'automatisation.
 *   Les adresses partagées sont comptées ensemble par construction : le seuil est configurable, le dépassement répond 429 sans rien créer, et une autre adresse
 *   ou un autre événement n'est jamais touché.
 * - Accusés de réception : par adresse destinataire (3 par heure), pour qu'une adresse tierce ne reçoive pas de rafale ; au-delà l'accusé est omis, le dépôt aboutit.
 *
 * Stockage STRICT : une panne d'écriture lève `RateLimiterStorageFailure` (503, journalisée), jamais un contournement silencieux (SEC-014).
 */
final readonly class PublicDepositThrottle
{
    private const MAX_WAIT = 3600;

    public function __construct(
        #[Autowire(service: 'limiter.public_request_submit')] private RateLimiterFactoryInterface $deposits,
        #[Autowire(service: 'limiter.public_request_receipt_email')] private RateLimiterFactoryInterface $receipts,
    ) {
    }

    /** @return int|null null si le dépôt est admis ; sinon le nombre de secondes à attendre */
    public function checkDeposit(Request $request, string $eventId): ?int
    {
        $limit = $this->deposits->create(ClientNetworkKey::of($request).'|'.$eventId)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, min(self::MAX_WAIT, $limit->getRetryAfter()->getTimestamp() - time()));
    }

    public function allowReceipt(string $email): bool
    {
        return $this->receipts->create(hash('sha256', mb_strtolower(trim($email))))->consume()->isAccepted();
    }
}
