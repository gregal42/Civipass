<?php

declare(strict_types=1);

namespace App\Identity\Application;

/** Une ligne du rapport : identifiant, catégorie technique, adresse MASQUÉE et effectifs ; jamais de nom ni de contenu. */
final readonly class AccountCandidateLine
{
    public const DELETABLE = 'DELETABLE';
    public const ANONYMISE_ONLY = 'ANONYMISE_ONLY';

    /**
     * @param array<string, int> $references effectifs de références CONSERVÉES par `table.colonne` (non nuls seulement)
     */
    public function __construct(
        public string $userId,
        public string $maskedEmail,
        public string $category,
        public bool $active,
        public string $lastActivityMonth,
        public array $references,
    ) {
    }
}
