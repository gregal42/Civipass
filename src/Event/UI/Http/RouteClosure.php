<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

/** Classement d'une route vis-à-vis de la clôture d'un événement (LOT 05.6.2). */
enum RouteClosure: string
{
    /** Ressource d'exploitation d'une commune identifiée par `{requestId}` : la barrière répond 410 sur un événement clos. */
    case Guarded = 'GUARDED';
    /** Route de commune sans identifiant de dossier (liste, recherche, saisie) : filtrée PAR LE LECTEUR (garde métier), testée. */
    case BusinessGuarded = 'BUSINESS_GUARDED';
    /** Aucune donnée personnelle servie (justification dans le classifieur). */
    case Exempt = 'EXEMPT';
    /** Chemins de cycle de vie séparés (archives du 05.6.3, purge du 05.6.4) : jamais derrière la barrière. */
    case Reserved = 'RESERVED';
    /** Hors espace municipal ; sa propre logique métier ferme l'accès et un test dédié le vérifie. */
    case Public = 'PUBLIC';
    /** Route inconnue : le test de matrice échoue. */
    case Unclassified = 'UNCLASSIFIED';
}
