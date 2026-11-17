<?php

declare(strict_types=1);

namespace App\Control\Domain;

use Symfony\Component\Uid\Uuid;

interface ControlActivationRepository
{
    public function add(ControlActivation $activation): void;

    public function find(Uuid $id): ?ControlActivation;

    /**
     * Codes créés pour une commune et un événement, les plus récents d'abord.
     *
     * @return list<ControlActivation>
     */
    public function listFor(Uuid $municipalityId, Uuid $eventId, int $limit = 20): array;

    /** Codes encore utilisables (non annulés, non expirés, appareils restants). */
    public function countUsable(Uuid $municipalityId, Uuid $eventId, \DateTimeImmutable $now): int;

    /**
     * CONSOMMATION ATOMIQUE d'un usage : une seule mise à jour conditionnelle (code non annulé, non expiré, appareils restants). Retourne l'identifiant de
     * l'activation, ou nul si le code est inconnu, annulé, expiré ou épuisé (le motif n'est jamais distingué). Aucune lecture préalable.
     */
    public function consumeUse(string $codeHash, \DateTimeImmutable $now): ?Uuid;

    /** Annule un code non encore annulé ni expiré ; faux s'il n'est plus annulable. */
    public function revokeIfOpen(Uuid $id, Uuid $by, \DateTimeImmutable $now): bool;
}
