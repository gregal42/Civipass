<?php

declare(strict_types=1);

namespace App\Control\Domain;

/**
 * Secret d'appareil (DM-131, DT-056) : 256 bits, base64url (43 caractères), remis UNE FOIS à l'appareil à l'enregistrement ; seule l'empreinte SHA-256
 * est stockée. Transmis dans l'en-tête `Authorization`, jamais dans une URL, jamais journalisé ni écrit dans l'audit.
 */
final readonly class DeviceSecret
{
    public const LENGTH = 43;

    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    public static function tryFromHeader(#[\SensitiveParameter] ?string $header): ?self
    {
        if (null === $header || 1 !== preg_match('/^Bearer ([A-Za-z0-9_-]{'.self::LENGTH.'})$/', trim($header), $m)) {
            return null;
        }

        return new self($m[1]);
    }

    public static function tryFromValue(#[\SensitiveParameter] string $value): ?self
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{'.self::LENGTH.'}$/', $value) ? new self($value) : null;
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
