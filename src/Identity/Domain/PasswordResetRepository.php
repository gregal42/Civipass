<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface PasswordResetRepository
{
    public function add(PasswordResetRequest $request): void;

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest;

    /**
     * Consommation ATOMIQUE : un seul appelant obtient `true`, même en concurrence (UPDATE conditionnel sur
     * used_at IS NULL et expires_at > maintenant, résultat = nombre de lignes modifiées).
     */
    public function consume(Uuid $requestId, \DateTimeImmutable $now): bool;

    /**
     * Invalide les demandes encore ouvertes d'un utilisateur ; retourne leur nombre.
     */
    public function invalidateOpenRequestsOf(User $user, \DateTimeImmutable $now): int;
}
