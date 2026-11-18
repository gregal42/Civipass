<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Request\Application\Read\RefusalNoticeFacts;
use App\Request\Application\Read\RefusalToCommunicate;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt de l'information des demandeurs refusés (LOT 08.5). Les transitions d'envoi sont des mises à jour CONDITIONNELLES ATOMIQUES (le bail fait foi) : deux
 * distributeurs simultanés ne traitent jamais la même ligne, et un traitement dont le bail a expiré ne peut pas écraser celui qui l'a repris.
 */
interface RefusalNoticeRepository
{
    public function add(RefusalNotice $notice): void;

    public function find(Uuid $id): ?RefusalNotice;

    public function findByDecision(Uuid $decisionId): ?RefusalNotice;

    /** @return list<Uuid> identifiants échus (en attente, échéance atteinte, sans bail en cours), les plus anciens d'abord */
    public function findDue(\DateTimeImmutable $now, int $limit): array;

    /**
     * Réserve la ligne (bail), compte l'essai et fixe le début de la fenêtre de 24 h au premier essai. Faux si une autre exécution l'a prise.
     *
     * @return array{attempts: int, firstAttemptAt: \DateTimeImmutable}|null
     */
    public function claim(Uuid $id, \DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): ?array;

    /** Clôt le traitement (SENT, ABANDONED, NO_RECIPIENT) si le bail est encore le nôtre. */
    public function finish(Uuid $id, \DateTimeImmutable $leaseUntil, RefusalNoticeStatus $status, \DateTimeImmutable $now, ?string $errorCode): bool;

    /** Replanifie après un échec temporaire si le bail est encore le nôtre. */
    public function retryAt(Uuid $id, \DateTimeImmutable $leaseUntil, \DateTimeImmutable $next, string $errorCode): bool;

    public function facts(Uuid $id): ?RefusalNoticeFacts;

    public function countToCommunicate(Uuid $municipalityId): int;

    /** @return list<RefusalToCommunicate> */
    public function toCommunicate(Uuid $municipalityId, int $limit): array;
}
