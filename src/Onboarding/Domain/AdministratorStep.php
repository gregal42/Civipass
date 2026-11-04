<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** Étape du parcours d'un lien PERSONNEL d'administrateur, selon l'état de la commune et du compte. */
enum AdministratorStep: string
{
    /** La commune a (désormais) un administrateur : ce lien ne donne aucun droit. */
    case CommuneAdministered = 'COMMUNE_ADMINISTERED';
    /** Un compte existe pour cette adresse : se connecter avec lui. */
    case LoginRequired = 'LOGIN_REQUIRED';
    /** Connecté avec un autre compte que celui de l'adresse invitée. */
    case WrongAccount = 'WRONG_ACCOUNT';
    /** Connecté avec le bon compte : confirmer. */
    case Confirm = 'CONFIRM';
    /** Aucun compte pour cette adresse : en créer un (l'adresse est celle du lien). */
    case Signup = 'SIGNUP';
}
