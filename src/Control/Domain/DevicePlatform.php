<?php

declare(strict_types=1);

namespace App\Control\Domain;

/** Plateforme grossière déclarée par l'appareil (aide à l'assistance seulement) ; jamais l'agent utilisateur complet. */
enum DevicePlatform: string
{
    case Android = 'ANDROID';
    case Ios = 'IOS';
    case Other = 'OTHER';

    public static function fromDeclared(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value))) ?? self::Other;
    }
}
