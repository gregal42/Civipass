<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum AuditAction: string
{
    case MembershipCreated = 'MEMBERSHIP_CREATED';
    case MembershipRolesChanged = 'MEMBERSHIP_ROLES_CHANGED';
    case MembershipActivated = 'MEMBERSHIP_ACTIVATED';
    case MembershipDeactivated = 'MEMBERSHIP_DEACTIVATED';
    case MunicipalityStatusChanged = 'MUNICIPALITY_STATUS_CHANGED';
    case MunicipalityContactChanged = 'MUNICIPALITY_CONTACT_CHANGED';
    case UserSuperAdminChanged = 'USER_SUPER_ADMIN_CHANGED';
    case UserActivationChanged = 'USER_ACTIVATION_CHANGED';
    case UserPasswordReset = 'USER_PASSWORD_RESET';
}
