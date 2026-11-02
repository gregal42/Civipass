<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Jeton de réinitialisation : 32 octets cryptographiquement aléatoires, jamais stockés en clair.
 * Seule l'empreinte SHA-256 est conservée ; le jeton ne circule que dans l'email puis dans la session de l'utilisateur.
 */
final readonly class PasswordResetToken
{
    private function __construct(public string $plain)
    {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    public static function fromPlain(string $plain): self
    {
        return new self($plain);
    }

    public function hash(): string
    {
        return hash('sha256', $this->plain);
    }

    /**
     * Forme attendue d'un jeton (43 caractères base64url) : évite toute requête pour une valeur manifestement invalide.
     */
    public static function looksValid(string $plain): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $plain);
    }
}
