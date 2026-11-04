<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\InvitationEmailContext;
use App\Onboarding\Domain\InvitationLink;

/**
 * Notification des invitations (port). L'implémentation de production envoie un e-mail HTML + texte via Symfony Mailer ;
 * le jeton en clair ne circule que dans le lien de cet e-mail.
 */
interface InvitationNotifier
{
    /**
     * @throws InvitationDeliveryFailed envoi impossible (transport indisponible, adresse refusée) ; jamais le jeton ni
     *                                  l'adresse dans le message
     */
    public function sendContactInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context): void;

    /**
     * E-mail PERSONNEL à la personne désignée comme administrateur communal par le contact de l'invitation. `maskedContact`.
     *
     * (« c***@domaine ») lui permet de reconnaître l'origine de la demande sans exposer l'adresse complète.
     *
     * @throws InvitationDeliveryFailed
     */
    public function sendAdministratorInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context, string $maskedContact): void;
}
