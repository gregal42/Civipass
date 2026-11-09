<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Secret du lien bénéficiaire (LOT 05.2, DM-114, DT-048) : 256 bits aléatoires, encodés en base64url sans remplissage (43 caractères).
 * Il n'existe que le temps de son affichage : seule son empreinte SHA-256 est conservée (jamais le secret, jamais l'URL complète). Il est
 * distinct du jeton de vérification du QR et n'en dérive en rien.
 */
final readonly class AccessSecret
{
    public const LENGTH = 43;

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /** Valeur reçue d'une URL (entrée non fiable) : `null` si elle n'a pas la forme d'un secret, sans jamais lever d'exception ni citer la valeur. */
    public static function tryFrom(string $value): ?self
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{'.self::LENGTH.'}$/', $value) ? new self($value) : null;
    }

    /** Empreinte SHA-256 hexadécimale (64 caractères) : la seule trace conservée du secret. */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
