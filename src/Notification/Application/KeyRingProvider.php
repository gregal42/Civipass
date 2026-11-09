<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\KeyRing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit le trousseau dans l'environnement (`NOTIFICATION_KEYRING`, `NOTIFICATION_ACTIVE_KEY_ID`), à la demande : une configuration absente ou
 * invalide ne fait jamais échouer le démarrage de l'application ni une approbation, elle rend simplement le trousseau inutilisable (D3).
 */
class KeyRingProvider
{
    public function __construct(
        #[\SensitiveParameter] #[Autowire(env: 'default::NOTIFICATION_KEYRING')] private readonly ?string $keyring = null,
        #[Autowire(env: 'default::NOTIFICATION_ACTIVE_KEY_ID')] private readonly ?string $activeKeyId = null,
    ) {
    }

    public function keyRing(): KeyRing
    {
        return KeyRing::parse($this->keyring, $this->activeKeyId);
    }
}
