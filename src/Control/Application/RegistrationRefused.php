<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Shared\Domain\DomainRuleViolation;

/** Refus UNIFORME d'un enregistrement d'appareil : code inconnu, annulé, expiré ou épuisé, ou plafond atteint ; le motif n'est jamais distingué. */
final class RegistrationRefused extends DomainRuleViolation
{
    public static function uniform(): self
    {
        return new self('Code d’activation invalide, expiré ou déjà utilisé.');
    }
}
