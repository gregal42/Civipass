<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface TwoFactorRepository
{
    public function findByUser(User $user): ?TwoFactorCredential;

    public function add(TwoFactorCredential $credential): void;

    public function addBackupCode(TwoFactorBackupCode $code): void;

    /**
     * Accepte un code TOTP ATOMIQUEMENT : un seul appelant obtient `true` pour un pas donné, même en concurrence (UPDATE conditionnel
     * `last_used_step < pas`). Interdit le rejeu d'un code déjà accepté.
     */
    public function acceptStep(TwoFactorCredential $credential, int $step): bool;

    /** Consomme un code de secours ATOMIQUEMENT (`used_at IS NULL`) : un seul appelant obtient `true`. */
    public function consumeBackupCode(TwoFactorCredential $credential, string $codeHash, \DateTimeImmutable $now): bool;

    public function remainingBackupCodes(TwoFactorCredential $credential): int;

    /** Supprime le second facteur du compte et ses codes de secours (réinitialisation) ; retourne `true` s'il existait. */
    public function remove(User $user): bool;
}
