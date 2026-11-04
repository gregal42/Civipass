<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

enum InvitationLinkKind: string
{
    /** Lien envoyé à l'adresse de contact saisie par l'organisatrice (pas nécessairement celle du futur administrateur). */
    case Contact = 'CONTACT';
    /** Lien personnel envoyé à la personne désignée par le contact comme administrateur communal. */
    case Administrator = 'ADMINISTRATOR';
}
