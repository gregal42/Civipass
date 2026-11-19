<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Mot de passe à usage unique basé sur le temps (TOTP, RFC 6238 ; HOTP, RFC 4226) : HMAC-SHA-1, pas de 30 s, 6 chiffres, compatible avec les applications
 * d'authentification courantes. Fonctions pures. Tolérance de ±1 pas (dérive d'horloge) ; un pas déjà accepté ne se rejoue pas (voir `TwoFactorService`).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret de 160 bits encodé en base32 (32 caractères), tel que l'attendent les applications d'authentification. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function stepAt(\DateTimeImmutable $moment): int
    {
        return intdiv($moment->getTimestamp(), self::PERIOD);
    }

    public static function code(#[\SensitiveParameter] string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24) | (\ord($hash[$offset + 1]) << 16) | (\ord($hash[$offset + 2]) << 8) | \ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /**
     * Pas correspondant au code saisi, dans la fenêtre de tolérance, ou `null`. La comparaison est à temps constant ; les pas sont tous évalués
     * (pas de sortie anticipée qui révélerait la position du pas valide).
     */
    public static function match(#[\SensitiveParameter] string $secret, string $input, \DateTimeImmutable $now): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (1 !== preg_match('/^\d{'.self::DIGITS.'}$/', $input)) {
            return null;
        }
        $current = self::stepAt($now);
        $matched = null;
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; ++$step) {
            if (hash_equals(self::code($secret, $step), $input)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function provisioningUri(#[\SensitiveParameter] string $secret, string $account, string $issuer = 'Civipass'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d', rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::PERIOD);
    }

    public static function base32Encode(#[\SensitiveParameter] string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(#[\SensitiveParameter] string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if (false === $position) {
                throw new \InvalidArgumentException('Secret base32 invalide.');
            }
            $bits .= str_pad(decbin($position), 5, '0', \STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
