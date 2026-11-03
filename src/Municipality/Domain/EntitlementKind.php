<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

/**
 * Origine d'un droit d'utilisation commercial (DM-076). L'absence d'abonnement payant n'est pas un refus :
 * un TRIAL valide ou un accès commercial accordé par CWS suffisent.
 */
enum EntitlementKind: string
{
    case Subscription = 'SUBSCRIPTION';
    case Trial = 'TRIAL';
    case CwsGrant = 'CWS_GRANT';
}
