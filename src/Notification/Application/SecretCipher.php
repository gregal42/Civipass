<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\EncryptedSecret;
use App\Notification\Domain\KeyRing;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SecretDecryptionFailed;
use Symfony\Component\Uid\Uuid;

/**
 * Chiffrement authentifié des secrets bénéficiaires (LOT 05.3.2, D1) : libsodium XChaCha20-Poly1305 (IETF), nonce de 24 octets aléatoire par
 * chiffrement, clé active du trousseau. Les données associées (authentifiées, non chiffrées) lient le secret au MESSAGE, à son TYPE et au
 * LAISSEZ-PASSER : un secret copié sur une autre ligne, ou une ligne dont le type ou le laissez-passer a été modifié, ne se déchiffre plus.
 * Ni le clair ni la clé ne figurent dans une exception.
 */
final class SecretCipher
{
    private const AAD_VERSION = 'civipass.notification.secret.v1';

    public function encrypt(KeyRing $ring, #[\SensitiveParameter] string $plaintext, Uuid $outboxId, NotificationType $type, Uuid $permitId): EncryptedSecret
    {
        [$keyId, $key] = $ring->active();
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::aad($outboxId, $type, $permitId), $nonce, $key);
        } catch (\SodiumException) {
            throw new \RuntimeException('Chiffrement impossible.');
        }

        return new EncryptedSecret($keyId, $nonce, $ciphertext);
    }

    /** @throws SecretDecryptionFailed */
    public function decrypt(KeyRing $ring, EncryptedSecret $encrypted, Uuid $outboxId, NotificationType $type, Uuid $permitId): string
    {
        $key = $ring->key($encrypted->keyId);
        if (null === $key || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== \strlen($encrypted->nonce)) {
            throw new SecretDecryptionFailed('Déchiffrement impossible.');
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($encrypted->ciphertext, self::aad($outboxId, $type, $permitId), $encrypted->nonce, $key);
        } catch (\SodiumException) {
            throw new SecretDecryptionFailed('Déchiffrement impossible.');
        }
        if (false === $plain) {
            throw new SecretDecryptionFailed('Déchiffrement impossible.');
        }

        return $plain;
    }

    private static function aad(Uuid $outboxId, NotificationType $type, Uuid $permitId): string
    {
        return implode('|', [self::AAD_VERSION, $outboxId->toRfc4122(), $type->value, $permitId->toRfc4122()]);
    }
}
