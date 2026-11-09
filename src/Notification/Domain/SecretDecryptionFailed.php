<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** Déchiffrement impossible (clé inconnue, données altérées ou liées à un autre message). Le message ne cite jamais de secret ni de clé. */
final class SecretDecryptionFailed extends \RuntimeException
{
}
