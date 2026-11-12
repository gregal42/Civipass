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

    // Correction d'identité du bénéficiaire (LOT 04.4, DM-111) : noms des champs modifiés seulement, jamais les valeurs. Une ligne par commune concernée.
    case RequestIdentityCorrected = 'REQUEST_IDENTITY_CORRECTED';

    // Laissez-passer (LOT 05.1, DM-112) : jamais le jeton ni une donnée personnelle ; une ligne par commune qui approuve.
    case PermitIssued = 'PERMIT_ISSUED';
    case PermitAuthorizationAdded = 'PERMIT_AUTHORIZATION_ADDED';
    case PermitAccessLinkIssued = 'PERMIT_ACCESS_LINK_ISSUED';
    case PermitAccessLinkRevoked = 'PERMIT_ACCESS_LINK_REVOKED';
    // Révisions des droits (LOT 05.5, DM-119) : identifiants et codes de motif seulement, jamais le commentaire, un jeton ni une plaque.
    case PermitAuthorizationRevoked = 'PERMIT_AUTHORIZATION_REVOKED';
    case PermitPeriodExtended = 'PERMIT_PERIOD_EXTENDED';
    case PermitPeriodResumed = 'PERMIT_PERIOD_RESUMED';
    case PermitPeriodShortened = 'PERMIT_PERIOD_SHORTENED';
    case PermitZoneAdded = 'PERMIT_ZONE_ADDED';
    case PermitZoneRemoved = 'PERMIT_ZONE_REMOVED';
    case PermitZoneReinstated = 'PERMIT_ZONE_REINSTATED';
    case PermitZoneLabelCorrected = 'PERMIT_ZONE_LABEL_CORRECTED';
    // Plaques (LOT 05.5.4) : jamais la plaque ni le commentaire. Une ligne par plaque pour la commune qui agit ; une ligne NEUTRE par autre commune titulaire.
    case PermitVehicleAdded = 'PERMIT_VEHICLE_ADDED';
    case PermitVehicleRemoved = 'PERMIT_VEHICLE_REMOVED';
    case PermitVehicleReinstated = 'PERMIT_VEHICLE_REINSTATED';
    case PermitVehicleReplacedOut = 'PERMIT_VEHICLE_REPLACED_OUT';
    case PermitVehicleReplacedIn = 'PERMIT_VEHICLE_REPLACED_IN';
    case PermitVehiclesChangedByOtherMunicipality = 'PERMIT_VEHICLES_CHANGED_BY_OTHER';
    // Remplacement du QR code (LOT 05.5.5) : identifiants, numéro d'ordre et code de motif ; JAMAIS un jeton ni le commentaire. Une ligne NEUTRE par autre commune titulaire.
    case PermitTokenReplaced = 'PERMIT_TOKEN_REPLACED';
    case PermitTokenReplacedByOtherMunicipality = 'PERMIT_TOKEN_REPLACED_BY_OTHER';

    // Notifications du bénéficiaire (LOT 05.3.4) : identifiants et codes seulement, jamais d'adresse ni de secret.
    case PermitNotificationRequested = 'PERMIT_NOTIFICATION_REQUESTED';
    case NotificationSuppressed = 'NOTIFICATION_SUPPRESSED';

    // Archives sécurisées (LOT 05.6.3) : identifiants et codes SEULEMENT, jamais un commentaire libre ni une valeur personnelle.
    case ArchiveManagerDesignated = 'ARCHIVE_MANAGER_DESIGNATED';
    case ArchiveManagerRevoked = 'ARCHIVE_MANAGER_REVOKED';
    case StatisticsCoordinationGranted = 'STATISTICS_COORDINATION_GRANTED';
    case StatisticsCoordinationRevoked = 'STATISTICS_COORDINATION_REVOKED';
    case LegalHoldPlaced = 'LEGAL_HOLD_PLACED';
    case LegalHoldReviewed = 'LEGAL_HOLD_REVIEWED';
    case LegalHoldLifted = 'LEGAL_HOLD_LIFTED';

    // Accès commerciaux CWS (DM-076, DT-030) : écrits par le lot Administration CWS.
    case CommercialAccessGranted = 'COMMERCIAL_ACCESS_GRANTED';
    case CommercialAccessRevoked = 'COMMERCIAL_ACCESS_REVOKED';
}
