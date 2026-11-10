<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Résultat d'un remplacement du QR : numéro d'ordre (1, 2, …), remplacement rapproché (moins d'une heure du précédent), signalé « inhabituel », rejoué (l'identifiant
 * d'opération avait déjà produit ce remplacement : rien n'est refait, le résultat initial est restitué) et présence d'une adresse e-mail (sinon remise par l'agent).
 * Jamais un jeton.
 */
final readonly class TokenReplacementResult
{
    public function __construct(
        public int $sequence,
        public bool $close,
        public bool $unusual,
        public bool $replayed,
        public bool $recipientAvailable,
    ) {
    }
}
