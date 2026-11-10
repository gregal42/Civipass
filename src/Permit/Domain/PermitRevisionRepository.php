<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/** Historique immuable des révisions : ajout et lecture seulement, jamais de modification ni de suppression (DM-119 §10). */
interface PermitRevisionRepository
{
    public function add(PermitRevision $revision): void;

    /** Plaque concernée par une révision de plaque (LOT 05.5.4) : la seule donnée personnelle des révisions. */
    public function addVehicleLine(PermitRevisionVehicle $line): void;

    /**
     * Révision (de n'importe quel type) qui porte ce `batch_id` dans CE laissez-passer, relue SOUS VERROU (lecture verrouillante : idempotence du remplacement du QR,
     * LOT 05.5.5). À appeler sous le verrou du laissez-passer.
     */
    public function lockedByBatch(Uuid $permitId, Uuid $batchId): ?PermitRevision;

    /**
     * Instants (UTC) des remplacements du QR du laissez-passer, du plus récent au plus ancien, relus SOUS VERROU (numéro d'ordre, remplacement rapproché, signalement).
     *
     * @return list<\DateTimeImmutable>
     */
    public function lockedTokenReplacementTimes(Uuid $permitId): array;

    /** Nombre de remplacements du QR faits par cet agent depuis cet instant (tous laissez-passer) : lecture simple, indicative (signalement). */
    public function countTokenReplacementsByActorSince(Uuid $userId, \DateTimeImmutable $since): int;

    /**
     * Lignes de plaques des révisions du laissez-passer, par identifiant de révision (RFC 4122).
     *
     * @return array<string, PermitRevisionVehicle>
     */
    public function vehicleLines(Uuid $permitId): array;

    /**
     * Toutes les révisions du laissez-passer, de la plus récente à la plus ancienne.
     *
     * @return list<PermitRevision>
     */
    public function forPermit(Uuid $permitId): array;
}
