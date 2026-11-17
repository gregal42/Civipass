<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

/**
 * Limitation de débit du contrôle public (LOT 05.4.3, révisée au LOT 07.3.2, DT-055). Deux plafonds INDÉPENDANTS par client (clé : VerificationClientKey) :
 * le plafond d'accès (garde-fou contre l'inondation : toutes les consultations) et le plafond des jetons bien formés mais inconnus. Le second ne
 * touche JAMAIS un jeton valide, retiré ou d'événement clos : il n'intervient qu'une fois la recherche faite, pour un jeton inconnu. Jamais le jeton :
 * seules des clés d'adresse y passent.
 */
interface VerificationThrottle
{
    /**
     * Compte une consultation contre le plafond d'accès. Retourne `null` si le client peut continuer, sinon le nombre de secondes à attendre (calculé, 1 à 900).
     * Applicable à TOUT jeton, valide compris : c'est le seul plafond qui peut refuser un jeton valide.
     *
     * @throws ThrottleStorageUnavailable le stockage des compteurs est en panne
     */
    public function check(string $clientKey): ?int;

    /**
     * Compte un échec (jeton bien formé mais inconnu) APRÈS la recherche. Retourne `null` tant que le client reste dans son allocation (réponse 404),
     * sinon le nombre de secondes à attendre (réponse 429). Ni un jeton mal formé, ni un jeton retiré, ni un laissez-passer lu ne sont des échecs.
     *
     * @throws ThrottleStorageUnavailable le stockage des compteurs est en panne
     */
    public function recordFailure(string $clientKey): ?int;
}
