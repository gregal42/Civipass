<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Demande de modification des plaques d'un laissez-passer (LOT 05.5.4) : plaques saisies à ajouter (texte brut, normalisé par le service), identifiants de lignes à
 * retirer ou à réactiver, remplacements (ligne qui sort, plaque qui entre) — valeurs de formulaire, jamais crues —, un code de motif par nature de changement
 * (ajout : facultatif ; retrait, remplacement et réactivation : obligatoires), un commentaire de 200 caractères SANS plaque et la confirmation de la portée GLOBALE
 * (les plaques sont communes à toutes les communes du laissez-passer).
 */
final readonly class VehicleChangeRequest
{
    /**
     * @param list<string>                          $adds
     * @param list<string>                          $removes
     * @param list<string>                          $reinstates
     * @param list<array{from: string, to: string}> $replacements
     */
    public function __construct(
        public array $adds = [],
        public array $removes = [],
        public array $reinstates = [],
        public array $replacements = [],
        public ?string $addReason = null,
        public ?string $removeReason = null,
        public ?string $replaceReason = null,
        public ?string $reinstateReason = null,
        public ?string $comment = null,
        public bool $confirmedGlobalScope = false,
    ) {
    }
}
