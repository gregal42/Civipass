<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Conflit TECHNIQUE récupérable avec une opération concurrente : verrou non obtenu à temps, interblocage ou
 * contrainte d'unicité violée par une écriture simultanée, ou état attendu périmé (StaleState : jamais de fusion automatique). L'opération a été annulée en entier (rien n'est écrit) et
 * peut être relancée par l'appelant après revalidation de l'intention de l'utilisateur ; aucune nouvelle tentative
 * automatique n'est faite.
 *
 * Ne traduit PAS les autres erreurs SQL ni les erreurs de programmation : elles remontent telles quelles.
 */
final class OperationConflict extends \RuntimeException
{
    public function __construct(public readonly ConflictCause $cause, ?\Throwable $previous = null)
    {
        parent::__construct(
            ConflictCause::StaleState === $cause
                ? 'Cette ressource a été modifiée depuis sa lecture : rien n\'a été écrit, relisez son état actuel avant de recommencer.'
                : 'Un autre traitement est en cours sur cette ressource : réessayez dans un instant.',
            0,
            $previous,
        );
    }
}
