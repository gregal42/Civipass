<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum FailureKind: string
{
    /** Réessayable : serveur indisponible, réponse 4xx, réseau, authentification ou configuration à corriger côté exploitation. */
    case Temporary = 'TEMPORARY';
    /** Refus définitif du serveur (5xx hors configuration) : jamais réessayé. */
    case Permanent = 'PERMANENT';
    /** Adresse syntaxiquement invalide : jamais réessayée. */
    case InvalidAddress = 'INVALID_ADDRESS';
}
