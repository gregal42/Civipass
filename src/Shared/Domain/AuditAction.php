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
    /** Synchronisation du référentiel national des communes (import console, origine système). */
    case NationalCommunesSynchronized = 'NATIONAL_COMMUNES_SYNCHRONIZED';
    /** Commune inscrite à partir du référentiel national, à l'occasion d'une invitation (aucun droit commercial). */
    /** Lien d'invitation émis (contact ou administrateur) : jamais le jeton ni l'adresse complète dans l'audit. */
    case InvitationLinkIssued = 'INVITATION_LINK_ISSUED';
    case InvitationEmailSent = 'INVITATION_EMAIL_SENT';
    case InvitationEmailFailed = 'INVITATION_EMAIL_FAILED';
    case InvitationAdministratorDelegated = 'INVITATION_ADMINISTRATOR_DELEGATED';
    case InvitationAdministratorEnrolled = 'INVITATION_ADMINISTRATOR_ENROLLED';
    case MunicipalityRegisteredFromReferential = 'MUNICIPALITY_REGISTERED_FROM_REFERENTIAL';
    case UserSuperAdminChanged = 'USER_SUPER_ADMIN_CHANGED';
    case UserActivationChanged = 'USER_ACTIVATION_CHANGED';
    case UserPasswordReset = 'USER_PASSWORD_RESET';

    // Module Event (DT-022, LOT TECH-02.1) : actions prévues, écrites par les services de l'étape 5.
    case EventCreated = 'EVENT_CREATED';
    case EventUpdated = 'EVENT_UPDATED';
    case EventActivated = 'EVENT_ACTIVATED';
    case EventSuspended = 'EVENT_SUSPENDED';
    case EventReactivated = 'EVENT_REACTIVATED';
    case EventClosed = 'EVENT_CLOSED';
    case ParticipationCreated = 'PARTICIPATION_CREATED';
    case ParticipationInvited = 'PARTICIPATION_INVITED';
    case ParticipationInvitationResent = 'PARTICIPATION_INVITATION_RESENT';
    case ParticipationAccepted = 'PARTICIPATION_ACCEPTED';
    case ParticipationSuspended = 'PARTICIPATION_SUSPENDED';
    case ParticipationReactivated = 'PARTICIPATION_REACTIVATED';
    case ParticipationClosed = 'PARTICIPATION_CLOSED';
    case ZoneCreated = 'ZONE_CREATED';
    case ZoneUpdated = 'ZONE_UPDATED';
    case ZoneConfirmed = 'ZONE_CONFIRMED';
    case ZoneSuspended = 'ZONE_SUSPENDED';
    case ZoneReactivated = 'ZONE_REACTIVATED';
    case ZoneDeleted = 'ZONE_DELETED';
    case ZoneValidityModeChanged = 'ZONE_VALIDITY_MODE_CHANGED';
    case ZoneValiditySlotCreated = 'ZONE_VALIDITY_SLOT_CREATED';
    case ZoneValiditySlotDeleted = 'ZONE_VALIDITY_SLOT_DELETED';
    case ZoneBulkOperation = 'ZONE_BULK_OPERATION';

    // Demandes de laissez-passer (LOT 03)
    case RequestSubmitted = 'REQUEST_SUBMITTED';

    // Décisions communales (LOT 04, DM-106) : une ligne par décision, sans commentaire libre ni donnée personnelle.
    case RequestApproved = 'REQUEST_APPROVED';
    case RequestRefused = 'REQUEST_REFUSED';

    // Accès commerciaux CWS (DM-076, DT-030) : écrits par le lot Administration CWS.
    case CommercialAccessGranted = 'COMMERCIAL_ACCESS_GRANTED';
    case CommercialAccessRevoked = 'COMMERCIAL_ACCESS_REVOKED';
}
