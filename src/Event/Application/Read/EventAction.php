<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Actions dont la fiche d'un événement indique la disponibilité (calculée côté serveur par la politique d'accès). */
enum EventAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Activate = 'activate';
    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
    case Close = 'close';
    case Invite = 'invite';
    case AcceptInvitation = 'accept_invitation';
    case SuspendParticipation = 'suspend_participation';
    case ReactivateParticipation = 'reactivate_participation';
    case CloseParticipation = 'close_participation';
    case CreateZone = 'create_zone';
    case UpdateZone = 'update_zone';
    case ConfirmZone = 'confirm_zone';
    case SuspendZone = 'suspend_zone';
    case ReactivateZone = 'reactivate_zone';
    case DeleteZone = 'delete_zone';
    case ConfigureValidity = 'configure_validity';
}
