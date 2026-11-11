<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Persistance des liens d'invitation. Aucune suppression physique : un lien se consomme ou se révoque.
 */
interface InvitationLinkRepository
{
    public function add(InvitationLink $link): void;

    public function find(Uuid $id): ?InvitationLink;

    public function findByTokenHash(string $tokenHash): ?InvitationLink;

    /**
     * Révoque les liens encore ouverts (ni consommés, ni révoqués) d'une participation pour un type donné, sauf `$exceptId` ;
     * renvoie leur nombre. Un nouvel envoi remplace le précédent : un seul lien ouvert par participation et par type.
     */
    public function revokeOpen(Uuid $participationId, InvitationLinkKind $kind, \DateTimeImmutable $now, ?Uuid $exceptId = null): int;

    /**
     * Révoque tous les liens encore ouverts (ni consommés, ni révoqués) des participations d'un événement, de tout type (clôture de l'événement,
     * LOT 05.6.2) ; renvoie leur nombre. Les liens consommés ne sont pas modifiés (historique).
     */
    public function revokeOpenOfEvent(Uuid $eventId, \DateTimeImmutable $now): int;

    /**
     * Consomme le lien de façon atomique (mise à jour conditionnelle : ni consommé, ni révoqué, non expiré) ; vrai si CE
     * appel l'a consommé. Deux consommations simultanées : une seule réussit.
     */
    public function consume(Uuid $id, \DateTimeImmutable $now): bool;

    /** Dernier lien de contact émis pour une participation (le plus récent), consommé ou non. */
    public function latestContact(Uuid $participationId): ?InvitationLink;
}
