<?php

declare(strict_types=1);

namespace App\Archive\Domain;

/** Cause de révocation d'une habilitation fine (désignation d'archive, coordination statistique) : une révocation est DÉFINITIVE, jamais réactivée. */
enum RevocationCause: string
{
    case AdminRequest = 'ADMIN_REQUEST';
    case RoleLost = 'ROLE_LOST';
    case MembershipDeactivated = 'MEMBERSHIP_DEACTIVATED';
    case UserDeactivated = 'USER_DEACTIVATED';
}
