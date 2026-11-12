<?php

declare(strict_types=1);

namespace App\Retention\Domain;

enum PurgeRecordKind: string
{
    /** Préavis envoyé aux administrateurs d'une commune pour un événement. */
    case Notice = 'NOTICE';
    /** Purge d'un dossier communal (niveau commune). */
    case Dossier = 'DOSSIER';
    /** Purge des données partagées d'une demande (niveau partagé). */
    case Shared = 'SHARED';
    /** Suppression définitive d'un événement. */
    case EventDeleted = 'EVENT_DELETED';
}
