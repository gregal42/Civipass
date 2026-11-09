<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestDecision;
use Symfony\Component\Uid\Uuid;

interface PermitRepository
{
    public function add(Permit $permit): void;

    /**
     * Laissez-passer de la demande, y compris celui créé plus tôt dans la même transaction mais pas encore écrit. `$locking` : lecture
     * VERROUILLANTE qui voit ce qu'une transaction concurrente vient de valider (REPEATABLE READ : une lecture simple ne le verrait pas).
     * À n'utiliser en verrouillant qu'APRÈS le verrou du compteur de référence : une lecture verrouillante d'une clé absente prend un
     * verrou d'intervalle et deux créateurs concurrents s'interbloqueraient.
     */
    public function findByRequest(Uuid $requestId, bool $locking = false): ?Permit;

    public function find(Uuid $permitId): ?Permit;

    /** Verrou exclusif de la ligne du laissez-passer (sérialise les demandes manuelles de notification ; après événement et participation). */
    public function lock(Uuid $permitId): void;

    public function findByVerificationToken(string $token): ?Permit;

    /** Vrai si la demande est déjà écrite en base (faux pour un dossier créé dans la transaction en cours, pas encore flushé). */
    public function isPersisted(PermitRequest $request): bool;

    /** Verrou exclusif de la ligne du dossier (ordre : après événement, participation, zones et part ; avant le compteur). Sans effet si le dossier n'est pas encore écrit. */
    public function lockRequest(PermitRequest $request): void;

    public function findDecision(Uuid $decisionId): ?PermitRequestDecision;

    /**
     * Approbations sans autorisation correspondante, les plus anciennes d'abord (rattrapage de développement).
     *
     * @return list<array{decisionId: Uuid, eventId: Uuid, participationId: Uuid, requestId: Uuid}>
     */
    public function findApprovalsWithoutAuthorization(): array;
}
