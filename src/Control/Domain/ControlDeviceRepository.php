<?php

declare(strict_types=1);

namespace App\Control\Domain;

use Symfony\Component\Uid\Uuid;

interface ControlDeviceRepository
{
    public function add(ControlDevice $device): void;

    public function find(Uuid $id): ?ControlDevice;

    /** Recherche par empreinte du secret (jamais par le secret lui-même). */
    public function findBySecretHash(string $secretHash): ?ControlDevice;

    /**
     * Appareils d'une commune pour un événement (via l'activation), les plus récents d'abord.
     *
     * @return list<ControlDevice>
     */
    public function listFor(Uuid $municipalityId, Uuid $eventId, int $limit = 100): array;

    /** Appareils en attente d'approbation dans le délai, pour une commune et un événement. */
    public function countPending(Uuid $municipalityId, Uuid $eventId, \DateTimeImmutable $now): int;

    /**
     * APPROBATION ATOMIQUE : un appareil `PENDING` dans le délai, dont le code d'appariement correspond, passe `ACTIVE` ; le code est effacé. Deux approbations
     * simultanées : une seule réussit. Retourne vrai si CETTE appel a approuvé.
     */
    public function approve(Uuid $id, string $pairingCode, Uuid $by, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): bool;

    /** DÉSACTIVATION ATOMIQUE (idempotente côté état : faux si déjà désactivé). Efface le code d'appariement. */
    public function deactivateIfActive(Uuid $id, ?Uuid $by, DeactivationReason $reason, \DateTimeImmutable $now): bool;

    /** Renouvelle l'échéance d'un appareil ACTIF (même expiré) ; faux sinon. */
    public function renew(Uuid $id, \DateTimeImmutable $newExpiresAt): bool;

    /** Enregistre une synchronisation réussie (instant, condensat racine, compteur). */
    public function recordSync(Uuid $id, \DateTimeImmutable $now, string $snapshotHash): void;
}
