<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

use App\Permit\Application\Read\PublicVerification;

/**
 * Résultat de la recherche d'un jeton de vérification. `verification` n'est présent que pour `Found` : « remplacé », « événement terminé », « inconnu » et « mal formé »
 * ne portent aucune donnée.
 */
final readonly class PublicVerificationLookup
{
    private function __construct(public PublicVerificationOutcome $outcome, public ?PublicVerification $verification)
    {
    }

    public static function found(PublicVerification $verification): self
    {
        return new self(PublicVerificationOutcome::Found, $verification);
    }

    public static function replaced(): self
    {
        return new self(PublicVerificationOutcome::Replaced, null);
    }

    public static function eventClosed(): self
    {
        return new self(PublicVerificationOutcome::EventClosed, null);
    }

    public static function unknown(): self
    {
        return new self(PublicVerificationOutcome::Unknown, null);
    }

    public static function malformed(): self
    {
        return new self(PublicVerificationOutcome::Malformed, null);
    }
}
