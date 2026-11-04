<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Issue d'une délégation : le lien personnel est émis ; l'e-mail peut avoir échoué (le destinataire peut recommencer). */
final readonly class DelegationResult
{
    public function __construct(public string $maskedAdministrator, public bool $emailSent)
    {
    }
}
