<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\MunicipalityAdminInvitation;

/** Résultat d'une émission ou d'un renvoi : l'invitation et l'issue de l'envoi (jamais le jeton). */
final readonly class AdminInvitationIssued
{
    public function __construct(public MunicipalityAdminInvitation $invitation, public bool $emailSent)
    {
    }
}
