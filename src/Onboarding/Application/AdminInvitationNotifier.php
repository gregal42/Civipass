<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\MunicipalityAdminInvitation;

/**
 * Envoi d'une invitation à rejoindre une commune (port) : émise par CWS (administrateur) ou par un administrateur communal (collaborateur). Le jeton en clair ne circule que dans le lien du
 * message ; il n'est jamais affiché dans le back-office.
 */
interface AdminInvitationNotifier
{
    /**
     * @throws InvitationDeliveryFailed envoi impossible ; jamais le jeton ni l'adresse dans le message de l'exception
     */
    public function send(MunicipalityAdminInvitation $invitation, #[\SensitiveParameter] string $plainToken, ?string $inviterName = null): void;
}
