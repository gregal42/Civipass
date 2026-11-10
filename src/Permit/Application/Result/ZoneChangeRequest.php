<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Demande de modification des zones d'UNE autorisation (LOT 05.5.3) : identifiants de zones à ajouter, retirer et réintégrer (valeurs de formulaire, jamais crues), un
 * code de motif par nature de changement présente (ajout : facultatif ; retrait et réintégration : obligatoires) et un commentaire commun de 200 caractères.
 */
final readonly class ZoneChangeRequest
{
    /**
     * @param list<string> $adds
     * @param list<string> $removes
     * @param list<string> $reinstates
     */
    public function __construct(
        public array $adds = [],
        public array $removes = [],
        public array $reinstates = [],
        public ?string $addReason = null,
        public ?string $removeReason = null,
        public ?string $reinstateReason = null,
        public ?string $comment = null,
    ) {
    }
}
