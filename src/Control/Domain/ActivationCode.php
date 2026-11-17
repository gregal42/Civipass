<?php

declare(strict_types=1);

namespace App\Control\Domain;

/**
 * Code d'activation d'appareil (DM-131, DT-056) : 12 caractères d'un alphabet de 32 (60 bits), générateur cryptographiquement sûr. Seule l'EMPREINTE SHA-256
 * est stockée ; le code n'est affiché qu'une fois, à l'administrateur qui le crée. Il ne figure jamais dans l'audit ni les journaux. Saisie tolérante
 * (casse, espaces, tirets, confusions O/0 et I/L/1) pour la frappe manuelle sur un téléphone.
 */
final readonly class ActivationCode
{
    public const LENGTH = 12;
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return new self($code);
    }

    /** `null` si la saisie n'a pas la forme d'un code (jamais d'exception : entrée non fiable). */
    public static function tryFromInput(#[\SensitiveParameter] string $input): ?self
    {
        $normalized = strtr(strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? ''), ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);

        return 1 === preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $normalized) ? new self($normalized) : null;
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    /** Présentation à l'administrateur (XXXX-XXXX-XXXX). */
    public function display(): string
    {
        return implode('-', str_split($this->value, 4));
    }

    public function value(): string
    {
        return $this->value;
    }
}
