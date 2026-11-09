<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface NotificationOutboxRepository
{
    public function find(\Symfony\Component\Uid\Uuid $id): ?NotificationOutbox;

    /** État courant relu en base (jamais l'instance en mémoire), sans verrou. */
    public function findFresh(\Symfony\Component\Uid\Uuid $id): ?NotificationOutbox;

    /**
     * Messages prêts à être traités : en attente et échus, ou réservés dont le bail a expiré. Les plus anciens d'abord. Lecture sans verrou :
     * la réservation (`lock` puis `reserve`) fait foi.
     *
     * @return list<\Symfony\Component\Uid\Uuid>
     */
    public function findDispatchable(\DateTimeImmutable $now, int $limit): array;

    public function statistics(\DateTimeImmutable $now, \DateInterval $failedWindow, \DateInterval $leaseGrace): OutboxStatistics;

    /** Ligne relue SOUS VERROU exclusif (état courant) : sérialise provisionnement, purge et distribution d'un même message. */
    public function lock(\Symfony\Component\Uid\Uuid $id): ?NotificationOutbox;

    /**
     * Messages en attente d'un lien : première approbation, état PENDING, sans secret enregistré.
     *
     * @return list<\Symfony\Component\Uid\Uuid>
     */
    public function findAwaitingSecret(int $limit): array;

    /**
     * Persiste la ligne (flush par la transaction appelante) ; sans effet si la clé existe déjà, y compris dans l'unité de travail en cours.
     *
     * @return bool vrai si la ligne a été ajoutée
     */
    public function addIfAbsent(NotificationOutbox $outbox): bool;

    /**
     * Messages de mise à disposition d'un laissez-passer (automatique et demandes manuelles), lus sous verrou PARTAGÉ : leur état ne peut pas
     * changer pendant la décision d'ordonnancement d'une extension.
     *
     * @return list<NotificationOutbox>
     */
    public function findFirstMessagesShared(\Symfony\Component\Uid\Uuid $permitId): array;

    /**
     * Messages ENVOYÉS d'un laissez-passer, tous types, sous verrou partagé : ce qu'ils ont annoncé couvre les extensions encore en attente.
     *
     * @return list<NotificationOutbox>
     */
    public function findSentShared(\Symfony\Component\Uid\Uuid $permitId): array;

    /**
     * Changements ordinaires d'un laissez-passer encore en attente (le groupe en cours), lus sous verrou exclusif pour être replanifiés.
     * Précondition (contrat du port d'enregistrement) : l'appelant tient déjà le verrou du laissez-passer.
     *
     * @return list<NotificationOutbox>
     */
    public function lockPendingOrdinary(\Symfony\Component\Uid\Uuid $permitId): array;

    /**
     * Demandes manuelles de renvoi déjà enregistrées pour ce laissez-passer (tous états), lues sous verrou partagé : une lecture simple, sous
     * REPEATABLE READ, ne verrait pas la demande qu'une transaction concurrente vient de valider. Précondition : verrou du laissez-passer tenu.
     *
     * @return list<NotificationOutbox>
     */
    public function findManualRequestsShared(\Symfony\Component\Uid\Uuid $permitId): array;

    /** Un message de mise à disposition est-il déjà PENDING ou RESERVED pour ce laissez-passer ? */
    public function hasActiveFirstMessage(\Symfony\Component\Uid\Uuid $permitId): bool;

    public function existsByKey(string $deduplicationKey): bool;

    /**
     * Messages en état DÉFINITIF dont le traitement date d'avant `$before` (purge des métadonnées). `$firstMessagesSent` : faux = tout SAUF les
     * messages de mise à disposition envoyés ; vrai = seulement ceux-là (ils servent à l'ordonnancement tant que le laissez-passer est utilisable).
     *
     * @return list<\Symfony\Component\Uid\Uuid>
     */
    public function findPurgeableBefore(\DateTimeImmutable $before, bool $firstMessagesSent, int $limit): array;

    public function remove(NotificationOutbox $outbox): void;

    /**
     * Tous les messages d'un laissez-passer, état courant, du plus récent au plus ancien (suivi agent).
     *
     * @return list<NotificationOutbox>
     */
    public function findForPermit(\Symfony\Component\Uid\Uuid $permitId): array;
}
