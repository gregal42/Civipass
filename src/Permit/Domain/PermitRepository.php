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

    /**
     * Relit l'autorisation SOUS VERROU exclusif de sa ligne (état à jour, même si une transaction concurrente vient de la modifier). À appeler après
     * `lock()` du laissez-passer, avant toute décision fondée sur son état (révocation).
     */
    public function refreshAuthorization(PermitAuthorization $authorization): void;

    /**
     * TOUTES les plaques du laissez-passer (actives et retirées), relues SOUS VERROU exclusif de leurs lignes (état à jour, même si une transaction concurrente vient de les
     * modifier ; lecture simple impossible en REPEATABLE READ), dans l'ordre de position. À appeler après `lock()` du laissez-passer (LOT 05.5.4).
     *
     * @return list<PermitVehicle>
     */
    public function lockVehicles(Permit $permit): array;

    /**
     * Jeton ACTIF du laissez-passer, relu SOUS VERROU exclusif de sa ligne (lecture verrouillante : un `SELECT` simple verrait un instantané périmé en `REPEATABLE READ`).
     * À appeler après `lock()` du laissez-passer (LOT 05.5.5).
     */
    public function lockActiveToken(Permit $permit): ?VerificationToken;

    /** Ce jeton existe-t-il déjà (actif OU retiré) ? Lecture simple : `UNIQUE(token)` reste le garde-fou final. */
    public function tokenExists(string $token): bool;

    /** Écrit immédiatement les changements en attente (ordre imposé par l'index unique du jeton actif : l'ancien jeton est écrit AVANT le nouveau). */
    public function flush(): void;

    /** Nombre de périodes CLOSES de l'autorisation, lu en base (à jour sous le verrou du laissez-passer) : position de la prochaine période archivée. */
    public function closedPeriodCount(Uuid $authorizationId): int;

    public function findByVerificationToken(string $token): ?Permit;

    /**
     * Jeton de vérification (actif OU retiré) et son laissez-passer, par la valeur lue dans l'URL du QR ; `null` si inconnu. L'appelant lit
     * `isActive()` : un jeton retiré ne doit jamais donner accès au laissez-passer. Lecture seule, un jeton déjà normalisé (minuscules).
     */
    public function findVerificationToken(#[\SensitiveParameter] string $token): ?VerificationToken;

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
