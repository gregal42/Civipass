<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use App\Municipality\Domain\Municipality;
use Symfony\Component\Uid\Uuid;

interface MunicipalityAdminInvitationRepository
{
    public function add(MunicipalityAdminInvitation $invitation): void;

    public function find(Uuid $id): ?MunicipalityAdminInvitation;

    public function findByTokenHash(string $tokenHash): ?MunicipalityAdminInvitation;

    /** @return list<MunicipalityAdminInvitation> plus récentes d'abord */
    public function findByMunicipality(Municipality $municipality): array;

    /**
     * Révoque (SQL conditionnel) les invitations encore ouvertes de la commune pour cette adresse, sauf `$exceptId`.
     *
     * @return int nombre de lignes révoquées
     */
    public function revokeOpen(Uuid $municipalityId, string $recipientEmail, Uuid $revokedBy, \DateTimeImmutable $now, ?Uuid $exceptId = null): int;

    /** Révocation atomique : vrai si CETTE requête a révoqué une invitation encore ouverte (exclusive avec `consume`). */
    public function revokeIfOpen(Uuid $id, Uuid $revokedBy, \DateTimeImmutable $now): bool;

    /** Consommation atomique : vrai si CETTE requête a consommé l'invitation (une seule gagnante). */
    public function consume(Uuid $id, Uuid $userId, \DateTimeImmutable $now): bool;

    /** Invitations utilisables (ni consommées, ni révoquées, ni expirées) de la commune. */
    public function countPending(Uuid $municipalityId, \DateTimeImmutable $now): int;
}
