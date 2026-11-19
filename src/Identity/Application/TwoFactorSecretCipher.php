<?php

declare(strict_types=1);

namespace App\Identity\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Chiffrement du secret TOTP au repos (SEC-006) : XChaCha20-Poly1305 (libsodium), clé dérivée du secret applicatif (`APP_SECRET`, HKDF-SHA-256, étiquette
 * dédiée), données associées = identifiant du compte (un secret copié sur un autre compte ne se déchiffre pas). Ni le clair ni la clé ne figurent dans une
 * exception. Changer `APP_SECRET` rend les secrets illisibles : les comptes concernés se réinscrivent (documenté dans le runbook d'accès).
 */
final readonly class TwoFactorSecretCipher
{
    private const INFO = 'civipass.two-factor.secret.v1';

    private string $key;

    public function __construct(#[\SensitiveParameter] #[Autowire('%kernel.secret%')] string $applicationSecret)
    {
        $this->key = hash_hkdf('sha256', $applicationSecret, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO);
    }

    public function encrypt(#[\SensitiveParameter] string $secret, Uuid $userId): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, self::aad($userId), $nonce, $this->key));
    }

    /** @throws \RuntimeException si le secret est illisible (altéré, autre compte, autre secret applicatif) */
    public function decrypt(string $stored, Uuid $userId): string
    {
        $raw = base64_decode($stored, true);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (false === $raw || \strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Second facteur illisible.');
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), self::aad($userId), substr($raw, 0, $nonceLength), $this->key);
        } catch (\SodiumException) {
            throw new \RuntimeException('Second facteur illisible.');
        }
        if (false === $plain) {
            throw new \RuntimeException('Second facteur illisible.');
        }

        return $plain;
    }

    /** Empreinte d'un code de secours : HMAC-SHA-256 avec une clé dérivée distincte (jamais le code en clair en base). */
    public function backupCodeHash(#[\SensitiveParameter] string $normalizedCode): string
    {
        return hash_hmac('sha256', $normalizedCode, hash_hkdf('sha256', $this->key, 32, 'civipass.two-factor.backup.v1'));
    }

    private static function aad(Uuid $userId): string
    {
        return self::INFO.'|'.$userId->toRfc4122();
    }
}
