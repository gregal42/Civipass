<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure\Security;

use App\Shared\Domain\KeyRing;
use App\Permit\Domain\TokenSealer;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Scelle le jeton de contrôle (SEC-020) : XChaCha20-Poly1305, données associées = identifiant de la ligne de jeton (une valeur copiée sur une autre ligne
 * ne se rouvre pas). Clé : trousseau DÉDIÉ `PERMIT_TOKEN_KEYRING` / `PERMIT_TOKEN_ACTIVE_KEY_ID` (format de `NOTIFICATION_KEYRING`), valeur stockée
 * `k1.<identifiant>.<base64>` ; toutes les clés du trousseau rouvrent (rotation : ajouter, activer, puis retirer l'ancienne quand plus aucune ligne ne
 * l'utilise ; `app:permit:protect-tokens --rewrap` ré-écrit les lignes). Sans trousseau (développement), repli sur une clé dérivée de `APP_SECRET`
 * (identifiant `app`), toujours rouvrable. Une rotation n'invalide AUCUN laissez-passer : le contrôle repose sur l'empreinte, pas sur cette copie.
 * Ni jeton ni clé dans une exception.
 */
#[AsAlias(TokenSealer::class)]
final readonly class PermitTokenSealer implements TokenSealer
{
    private const PREFIX = 'k1.';
    public const FALLBACK_ID = 'app';
    private const INFO = 'civipass.permit-token.v1';

    private KeyRing $ring;
    private string $fallbackKey;

    public function __construct(
        #[\SensitiveParameter] #[Autowire('%kernel.secret%')] string $applicationSecret,
        #[\SensitiveParameter] #[Autowire(env: 'default::PERMIT_TOKEN_KEYRING')] ?string $keyring = null,
        #[Autowire(env: 'default::PERMIT_TOKEN_ACTIVE_KEY_ID')] ?string $activeKeyId = null,
    ) {
        $this->ring = KeyRing::parse($keyring, $activeKeyId);
        $this->fallbackKey = hash_hkdf('sha256', $applicationSecret, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO);
    }

    public function usesDedicatedKey(): bool
    {
        return $this->ring->isUsable();
    }

    /** Identifiant de la clé qui scelle aujourd'hui (jamais la clé). */
    public function activeKeyId(): string
    {
        return $this->ring->isUsable() ? (string) $this->ring->activeId() : self::FALLBACK_ID;
    }

    public function seal(#[\SensitiveParameter] string $plain, Uuid $tokenId): string
    {
        [$id, $key] = $this->ring->isUsable() ? $this->ring->active() : [self::FALLBACK_ID, $this->fallbackKey];
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::PREFIX.$id.'.'.base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::aad($tokenId), $nonce, $key));
    }

    /** Identifiant de la clé qui a scellé cette valeur, ou `null` si la valeur n'a pas la forme attendue. */
    public function keyIdOf(string $sealed): ?string
    {
        $parts = explode('.', $sealed, 3);

        return 3 === \count($parts) && 'k1' === $parts[0] ? $parts[1] : null;
    }

    public function open(string $sealed, Uuid $tokenId): string
    {
        $parts = explode('.', $sealed, 3);
        if (3 !== \count($parts) || 'k1' !== $parts[0]) {
            throw new \RuntimeException('Jeton scellé illisible.');
        }
        $key = self::FALLBACK_ID === $parts[1] ? $this->fallbackKey : $this->ring->key($parts[1]);
        $raw = base64_decode($parts[2], true);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (null === $key || false === $raw || \strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Jeton scellé illisible.');
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), self::aad($tokenId), substr($raw, 0, $nonceLength), $key);
        } catch (\SodiumException) {
            throw new \RuntimeException('Jeton scellé illisible.');
        }
        if (false === $plain) {
            throw new \RuntimeException('Jeton scellé illisible.');
        }

        return $plain;
    }

    private static function aad(Uuid $tokenId): string
    {
        return self::INFO.'|'.$tokenId->toRfc4122();
    }
}
