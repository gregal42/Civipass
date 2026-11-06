<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

/**
 * Résultat d'un dépôt. `replayed` est vrai quand la tentative répète une tentative déjà enregistrée (même clé
 * d'idempotence) : aucun nouveau dossier n'a été créé et la référence d'origine est renvoyée.
 */
final readonly class SubmittedRequest
{
    public function __construct(
        public string $requestId,
        public string $reference,
        public bool $replayed,
    ) {
    }
}
