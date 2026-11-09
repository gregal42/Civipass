<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Événements du laissez-passer qui donnent lieu à une notification du bénéficiaire (LOT 05.3.1, DM-117). */
enum NotificationKind: string
{
    /** Première approbation : mise à disposition du laissez-passer. */
    case PermitIssued = 'PERMIT_ISSUED';
    /** Approbation d'une commune supplémentaire. */
    case AuthorizationAdded = 'AUTHORIZATION_ADDED';
}
