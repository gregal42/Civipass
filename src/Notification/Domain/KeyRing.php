<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Trousseau de clés de chiffrement des secrets bénéficiaires (LOT 05.3.2, D3). Fourni par l'environnement, jamais par le dépôt.
 * Format de `NOTIFICATION_KEYRING` : `identifiant:clé_base64[,identifiant:clé_base64…]` (clés de 32 octets) ; `NOTIFICATION_ACTIVE_KEY_ID`
 * désigne la clé qui chiffre ; toutes les clés du trousseau déchiffrent (rotation). Aucune valeur de clé n'apparaît dans une erreur,
 * un `var_dump` ou un état : seuls les identifiants sont publics.
 */
final class KeyRing
{
    public const KEY_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    public const EMPTY = 'KEYRING_EMPTY';
    public const MALFORMED = 'KEYRING_MALFORMED';
    public const ACTIVE_UNKNOWN = 'ACTIVE_KEY_UNKNOWN';

    /**
     * @param array<string, string> $keys clés brutes par identifiant
     */
    private function __construct(
        #[\SensitiveParameter] private readonly array $keys,
        private readonly ?string $activeId,
        private readonly ?string $problem,
    ) {
    }

    public static function parse(#[\SensitiveParameter] ?string $keyring, ?string $activeId): self
    {
        $keyring = trim((string) $keyring);
        $activeId = null === $activeId || '' === trim($activeId) ? null : trim($activeId);
        if ('' === $keyring) {
            return new self([], $activeId, self::EMPTY);
        }
        $keys = [];
        foreach (explode(',', $keyring) as $entry) {
            $parts = explode(':', trim($entry), 2);
            $raw = 2 === \count($parts) ? base64_decode($parts[1], true) : false;
            if (2 !== \count($parts) || 1 !== preg_match(self::ID_PATTERN, $parts[0]) || false === $raw || self::KEY_BYTES !== \strlen($raw) || isset($keys[$parts[0]])) {
                return new self([], $activeId, self::MALFORMED);
            }
            $keys[$parts[0]] = $raw;
        }
        if (null === $activeId || !isset($keys[$activeId])) {
            return new self([], $activeId, self::ACTIVE_UNKNOWN);
        }

        return new self($keys, $activeId, null);
    }

    public function isUsable(): bool
    {
        return null === $this->problem;
    }

    /** Code court de l'anomalie (jamais une valeur de clé), ou `null` si le trousseau est utilisable. */
    public function problem(): ?string
    {
        return $this->problem;
    }

    public function activeId(): ?string
    {
        return $this->activeId;
    }

    /** @return list<string> identifiants seulement */
    public function ids(): array
    {
        return array_keys($this->keys);
    }

    /** @return array{string, string} identifiant et clé brute de la clé active @throws \LogicException trousseau inutilisable */
    public function active(): array
    {
        if (null === $this->activeId || !isset($this->keys[$this->activeId])) {
            throw new \LogicException('Trousseau inutilisable.');
        }

        return [$this->activeId, $this->keys[$this->activeId]];
    }

    public function key(string $id): ?string
    {
        return $this->keys[$id] ?? null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['ids' => $this->ids(), 'activeId' => $this->activeId, 'problem' => $this->problem, 'keys' => '[masqué]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Un trousseau ne se sérialise pas.');
    }
}
