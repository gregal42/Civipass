<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** Secret chiffré (XChaCha20-Poly1305) : identifiant de clé, nonce de 24 octets et texte chiffré avec son étiquette d'authentification. */
final readonly class EncryptedSecret
{
    public function __construct(
        public string $keyId,
        #[\SensitiveParameter] public string $nonce,
        #[\SensitiveParameter] public string $ciphertext,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'nonce' => '[masqué]', 'ciphertext' => '[masqué]'];
    }
}
