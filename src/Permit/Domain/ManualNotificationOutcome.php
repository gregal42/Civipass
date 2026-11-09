<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Résultat d'une demande manuelle de nouvelle notification de mise à disposition (LOT 05.3.4). */
enum ManualNotificationOutcome: string
{
    /** Nouvelle intention enregistrée. */
    case Created = 'CREATED';
    /** Même formulaire déjà enregistré (double clic, rejeu) : aucune seconde intention. */
    case Duplicate = 'DUPLICATE';
    /** Un message de mise à disposition est déjà en attente d'envoi : pas de second envoi simultané. */
    case AlreadyPending = 'ALREADY_PENDING';
    /** Moins de 5 minutes depuis la dernière demande acceptée. */
    case TooSoon = 'TOO_SOON';
    /** Trois demandes acceptées dans les dernières 24 heures. */
    case DailyLimitReached = 'DAILY_LIMIT_REACHED';
}
