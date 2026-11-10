<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

/**
 * Limitation de débit du contrôle public (LOT 05.4.3). Deux plafonds INDÉPENDANTS par client (clé : VerificationClientKey) : les consultations,
 * et les jetons bien formés mais inconnus. Jamais le jeton : seules des clés d'adresse y passent.
 */
interface VerificationThrottle
{
    /**
     * Compte une consultation. Retourne `null` si le client peut continuer, sinon le nombre de secondes à attendre (calculé, 1 à 900).
     *
     * @throws ThrottleStorageUnavailable le stockage des compteurs est en panne
     */
    public function check(string $clientKey): ?int;

    /**
     * Compte un échec : jeton bien formé mais inconnu. Ni un jeton mal formé, ni un jeton retiré, ni un laissez-passer lu ne sont des échecs.
     *
     * @throws ThrottleStorageUnavailable le stockage des compteurs est en panne
     */
    public function recordFailure(string $clientKey): void;
}
