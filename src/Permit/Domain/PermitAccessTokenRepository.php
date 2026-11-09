<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

interface PermitAccessTokenRepository
{
    public function add(PermitAccessToken $token): void;

    /** Retrouve un lien par l'empreinte de son secret (comparaison en base sur la valeur hachée uniquement). */
    public function findByHash(string $hash): ?PermitAccessToken;

    /** Lien d'un laissez-passer relu SOUS VERROU exclusif (état courant) ; nul s'il n'appartient pas à ce laissez-passer. */
    public function lockForPermit(Uuid $tokenId, Uuid $permitId): ?PermitAccessToken;

    /** Lien par identifiant, état courant (relu en base), sans verrou. */
    public function find(Uuid $tokenId): ?PermitAccessToken;

    /** Lien relu SOUS VERROU exclusif par son identifiant seul (révocation par le système). */
    public function lock(Uuid $tokenId): ?PermitAccessToken;

    /**
     * @return list<PermitAccessToken> du plus récent au plus ancien
     */
    public function findByPermit(Uuid $permitId): array;
}
