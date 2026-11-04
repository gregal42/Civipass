<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

enum InvitationDeliveryStatus: string
{
    /** Lien émis, e-mail pas encore confirmé envoyé (ou arrêt entre l'émission et l'envoi). */
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Failed = 'FAILED';
}
