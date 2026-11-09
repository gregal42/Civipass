<?php

declare(strict_types=1);

namespace App\Request\Application;

/**
 * La participation de la commune à l'événement n'est pas ACTIVE (invitée, suspendue ou clôturée) : la correction d'identité est impossible
 * (DM-111, lecture seule). Relevée SOUS verrou de la participation : un changement de statut entre l'affichage du formulaire et son envoi ne
 * permet pas de contourner la règle. Aucune écriture n'a eu lieu.
 */
final class IdentityCorrectionUnavailable extends \RuntimeException
{
}
