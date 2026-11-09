<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Récapitulatif du rattrapage (aucune donnée personnelle). `errors` : classe de l'exception seulement, jamais de jeton ni d'identité.
 */
final readonly class BackfillReport
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public bool $dryRun,
        public int $approvalsMissing,
        public int $permitsToCreate,
        public int $permitsCreated,
        public int $authorizationsAdded,
        public array $errors,
    ) {
    }
}
