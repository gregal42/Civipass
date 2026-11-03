<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Municipality\Domain\MunicipalityRole;

/**
 * SOURCE UNIQUE de la matrice des permissions du module Event (DM-057). Chaque permission porte :
 * - le type de ressource sur laquelle elle s'applique (subject()) ;
 * - les rôles communaux autorisés, dans la commune propriétaire de la ressource (roles()) ;
 * - null pour les lectures : tout membership effectif suffit (voir EventAccessPolicy pour la portée).
 *
 * Aucun rôle global : `superAdmin` n'apparaît pas, il ne donne aucun droit communal implicite. L'agent instructeur
 * n'a aucune permission de gestion des événements ou des zones du seul fait de son rôle.
 *
 * Divergence signalée : le message d'instruction de l'étape 4 (§4.2) prête au gestionnaire la gestion des zones et
 * des créneaux, ce que DM-057 (validée) exclut : « ne peut pas administrer les zones sans être administrateur
 * communal ». DM-057 est appliqué ; changer la règle = modifier roles() ci-dessous, à un seul endroit.
 */
enum EventPermission: string
{
    // Événement
    case CreateEvent = 'EVENT_CREATE';
    case ViewEvent = 'EVENT_VIEW';
    case UpdateEvent = 'EVENT_UPDATE';
    case ActivateEvent = 'EVENT_ACTIVATE';
    case SuspendEvent = 'EVENT_SUSPEND';
    case ReactivateEvent = 'EVENT_REACTIVATE';
    case CloseEvent = 'EVENT_CLOSE';
    case InviteMunicipality = 'EVENT_INVITE';

    // Participation
    case ViewParticipation = 'PARTICIPATION_VIEW';
    case AcceptInvitation = 'PARTICIPATION_ACCEPT';
    case SuspendParticipation = 'PARTICIPATION_SUSPEND';
    case ReactivateParticipation = 'PARTICIPATION_REACTIVATE';
    case CloseParticipation = 'PARTICIPATION_CLOSE';

    // Zones
    case CreateZone = 'ZONE_CREATE';
    case ViewZone = 'ZONE_VIEW';
    case ModifyZone = 'ZONE_MODIFY';
    case DeleteZone = 'ZONE_DELETE';
    case ConfigureZoneValidity = 'ZONE_VALIDITY_CONFIGURE';

    // Créneaux
    case DeleteZoneValiditySlot = 'ZONE_VALIDITY_SLOT_DELETE';

    public function subject(): PermissionSubject
    {
        return match ($this) {
            self::CreateEvent => PermissionSubject::NewEvent,
            self::ViewEvent, self::UpdateEvent, self::ActivateEvent, self::SuspendEvent, self::ReactivateEvent,
            self::CloseEvent, self::InviteMunicipality => PermissionSubject::Event,
            self::ViewParticipation, self::AcceptInvitation, self::SuspendParticipation, self::ReactivateParticipation,
            self::CloseParticipation, self::CreateZone => PermissionSubject::Participation,
            self::ViewZone, self::ModifyZone, self::DeleteZone, self::ConfigureZoneValidity => PermissionSubject::Zone,
            self::DeleteZoneValiditySlot => PermissionSubject::ZoneValiditySlot,
        };
    }

    public function isRead(): bool
    {
        return \in_array($this, [self::ViewEvent, self::ViewParticipation, self::ViewZone], true);
    }

    /**
     * @return list<MunicipalityRole>|null null = lecture ouverte à tout membership effectif
     */
    public function roles(): ?array
    {
        $adminOrManager = [MunicipalityRole::MunicipalityAdmin, MunicipalityRole::EventManager];

        return match ($this) {
            self::ViewEvent, self::ViewParticipation, self::ViewZone => null,
            // Gestion des événements de sa commune, invitations, participation de sa commune.
            self::CreateEvent, self::UpdateEvent, self::ActivateEvent, self::SuspendEvent, self::ReactivateEvent,
            self::CloseEvent, self::InviteMunicipality, self::SuspendParticipation, self::ReactivateParticipation,
            self::CloseParticipation => $adminOrManager,
            // Accepter une invitation : administrateur communal seul (R2).
            self::AcceptInvitation => [MunicipalityRole::MunicipalityAdmin],
            // Zones et créneaux : administrateur communal seul (DM-009, DM-057).
            self::CreateZone, self::ModifyZone, self::DeleteZone, self::ConfigureZoneValidity,
            self::DeleteZoneValiditySlot => [MunicipalityRole::MunicipalityAdmin],
        };
    }
}
