<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

/** État d'un lien d'invitation tel que l'utilisateur doit le comprendre. */
enum LinkStatus: string
{
    /** Jeton inconnu ou mal formé : aucune information n'est donnée. */
    case Unknown = 'UNKNOWN';
    case Expired = 'EXPIRED';
    /** Déjà utilisé (administrateur inscrit). */
    case Used = 'USED';
    /** Remplacé par un envoi plus récent. */
    case Replaced = 'REPLACED';
    /** L'invitation n'est plus en attente (acceptée, clôturée, événement clôturé) ou la commune n'est plus disponible. */
    case Inactive = 'INACTIVE';
    case Usable = 'USABLE';
}
