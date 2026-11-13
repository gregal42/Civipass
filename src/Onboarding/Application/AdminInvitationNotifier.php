<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\MunicipalityAdminInvitation;

/**
 * Envoi de l'invitation d'administrateur communal émise par CWS (port). Le jeton en clair ne circule que dans le lien du
 * message ; il n'est jamais affiché dans le back-office.
 */
interface AdminInvitationNotifier
{
    /**
     * @throws InvitationDeliveryFailed envoi impossible ; jamais le jeton ni l'adresse dans le message de l'exception
     */
    public function send(MunicipalityAdminInvitation $invitation, #[\SensitiveParameter] string $plainToken): void;
}
