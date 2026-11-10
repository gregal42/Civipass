<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Demande de remplacement du QR code d'un laissez-passer (LOT 05.5.5) : identifiant d'opération du formulaire (UUID généré à l'affichage : il devient le `batch_id` de la
 * révision et rend l'opération idempotente), code de motif obligatoire, commentaire de 200 caractères (jamais un jeton), confirmation de l'effet GLOBAL et référence
 * ressaisie (exigée seulement pour un second remplacement dans l'heure). Valeurs de formulaire : jamais crues.
 */
final readonly class TokenReplacementRequest
{
    public function __construct(
        public string $operationId,
        public ?string $reason = null,
        public ?string $comment = null,
        public bool $confirmedGlobalEffect = false,
        public ?string $reference = null,
    ) {
    }
}
