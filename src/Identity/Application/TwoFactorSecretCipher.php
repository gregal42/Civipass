<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Domain\KeyRing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Chiffrement du secret TOTP au repos (SEC-006) : XChaCha20-Poly1305 (libsodium), données associées = identifiant du compte (un secret copié sur un autre
 * compte ne se déchiffre pas). Ni le clair ni une clé ne figurent dans une exception.
 *
 * CLÉ : un trousseau DÉDIÉ (`TWO_FACTOR_KEYRING` / `TWO_FACTOR_ACTIVE_KEY_ID`, même format et même classe `KeyRing` que les notifications) chiffre en
 * `k1.<identifiant>.<base64>` ; toutes les clés du trousseau déchiffrent : on ajoute la nouvelle clé, on la déclare active, puis on retire l'ancienne quand
 * plus aucun secret ne l'utilise (rotation sans réinscription). Sans trousseau configuré (développement), repli sur une clé dérivée de `APP_SECRET`
 * (HKDF, format historique sans préfixe) : ces secrets restent TOUJOURS lisibles, donc passer à un trousseau dédié ne force aucune réinscription.
 *
 * Les empreintes des codes de secours utilisent un poivre dérivé de `APP_SECRET` (stable, indépendant de la rotation des clés de chiffrement).
 */
final readonly class TwoFactorSecretCipher
{
    private const INFO = 'civipass.two-factor.secret.v1';
    private const PREFIX = 'k1.';

    private string $legacyKey;
    private KeyRing $ring;

    public function __construct(
        #[\SensitiveParameter] #[Autowire('%kernel.secret%')] string $applicationSecret,
        #[\SensitiveParameter] #[Autowire(env: 'default::TWO_FACTOR_KEYRING')] ?string $keyring = null,
        #[Autowire(env: 'default::TWO_FACTOR_ACTIVE_KEY_ID')] ?string $activeKeyId = null,
    ) {
        $this->legacyKey = hash_hkdf('sha256', $applicationSecret, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO);
        $this->ring = KeyRing::parse($keyring, $activeKeyId);
    }

    /** Vrai si un trousseau dédié, valide, chiffre les nouveaux secrets. */
    public function usesDedicatedKey(): bool
    {
        return $this->ring->isUsable();
    }

    public function encrypt(#[\SensitiveParameter] string $secret, Uuid $userId): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        if ($this->ring->isUsable()) {
            [$id, $key] = $this->ring->active();

            return self::PREFIX.$id.'.'.base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, self::aad($userId), $nonce, $key));
        }

        return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, self::aad($userId), $nonce, $this->legacyKey));
    }

    /** @throws \RuntimeException si le secret est illisible (altéré, autre compte, autre secret applicatif) */
    public function decrypt(string $stored, Uuid $userId): string
    {
        $key = $this->legacyKey;
        if (str_starts_with($stored, self::PREFIX)) {
            $parts = explode('.', $stored, 3);
            $key = 3 === \count($parts) ? $this->ring->key($parts[1]) : null;
            if (null === $key) {
                throw new \RuntimeException('Second facteur illisible.');
            }
            $stored = $parts[2];
        }
        $raw = base64_decode($stored, true);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (false === $raw || \strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Second facteur illisible.');
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), self::aad($userId), substr($raw, 0, $nonceLength), $key);
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
        return hash_hmac('sha256', $normalizedCode, hash_hkdf('sha256', $this->legacyKey, 32, 'civipass.two-factor.backup.v1'));
    }

    private static function aad(Uuid $userId): string
    {
        return self::INFO.'|'.$userId->toRfc4122();
    }
}
