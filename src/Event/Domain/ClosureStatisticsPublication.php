<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Secret statistique des bilans de clôture (LOT 05.6.2, DM-120, Q6, P5). Seuil `T` PROVISOIRE (5) soumis à validation du DPO. Règles :
 *
 *  - R1 : un effectif n'est publié que s'il vaut 0 ou au moins `T` (un zéro n'est pas une observation individuelle ; risque résiduel soumis au DPO : « aucun
 *    refus » sur au moins `T` dossiers révèle que tous ont été approuvés) ;
 *  - R2 : la répartition par statut et les autorisations forment UN groupe « tout ou rien » : si une seule cellule est masquée, tout le groupe l'est (aucune
 *    cellule masquée ne se déduit alors du total ou d'une autre cellule) ;
 *  - R3 : la ventilation par commune est « tout ou rien » ; sans elle, le TOTAL global n'est pas publié non plus dans un événement multicommunal (sinon
 *    « total − sa propre ligne − celle d'une autre commune » dévoilerait l'effectif d'une troisième) ;
 *  - R4 : la répartition par statut GLOBALE n'est publiée que pour un événement à UNE commune ; sinon chaque commune ne voit que SA propre répartition (aucune
 *    soustraction global − commune n'est possible) ;
 *  - R5 : aucun croisement (commune × statut pour un autre lecteur), aucune répartition par catégorie ni par motif.
 *
 * Fonctions pures : testées par énumération exhaustive (aucune cellule sensible ne se déduit des vues d'un lecteur, ni de leur combinaison).
 */
final class ClosureStatisticsPublication
{
    public const DEFAULT_THRESHOLD = 5;
    public const MIN_THRESHOLD = 2;

    private function __construct()
    {
    }

    public static function threshold(int $configured): int
    {
        return max(self::MIN_THRESHOLD, $configured);
    }

    public static function publishable(int $count, int $threshold): bool
    {
        return 0 === $count || $count >= $threshold;
    }

    /**
     * Vue de la commune ORGANISATRICE (rôles de gestion). `$breakdown` : habilitation de coordination (ventilation par commune).
     */
    public static function forOrganizer(ClosureStatistics $statistics, int $threshold, bool $breakdown): PublishedClosureStatistics
    {
        $threshold = self::threshold($threshold);
        $rows = $statistics->municipalities;
        $masked = [];
        $multi = \count($rows) > 1;

        $communes = null;
        if ($multi && $breakdown) {
            $all = true;
            foreach ($rows as $row) {
                $all = $all && self::publishable($row->dossiers(), $threshold);
            }
            if ($all) {
                $communes = array_map(static fn (MunicipalityCounters $m): array => ['municipalityId' => $m->municipalityId, 'dossiers' => $m->dossiers()], $rows);
            } else {
                $masked[] = 'communes';
            }
        }

        $total = $statistics->dossiers();
        // R3 : dans un événement multicommunal, le total n'est publié que si TOUS les effectifs communaux le sont (même sans habilitation de ventilation).
        $totalOk = self::publishable($total, $threshold);
        if ($multi) {
            foreach ($rows as $row) {
                $totalOk = $totalOk && self::publishable($row->dossiers(), $threshold);
            }
        }
        $dossiers = $totalOk ? $total : null;
        if (!$totalOk) {
            $masked[] = 'dossiers';
        }

        $byStatus = null;
        if (!$multi) {
            $byStatus = self::group($rows, $threshold);
            if (null === $byStatus) {
                $masked[] = 'byStatus';
            }
        }

        return new PublishedClosureStatistics(PublishedClosureStatistics::SCOPE_GLOBAL, $threshold, $dossiers, $byStatus, $communes, $masked);
    }

    /**
     * Vue d'une commune : SA PROPRE ligne seulement (jamais le global ni les autres communes).
     */
    public static function forMunicipality(ClosureStatistics $statistics, string $municipalityId, int $threshold): PublishedClosureStatistics
    {
        $threshold = self::threshold($threshold);
        $own = [];
        foreach ($statistics->municipalities as $row) {
            if ($row->municipalityId === $municipalityId) {
                $own[] = $row;
            }
        }
        $masked = [];
        $dossiers = [] === $own ? 0 : $own[0]->dossiers();
        if (!self::publishable($dossiers, $threshold)) {
            $dossiers = null;
            $masked[] = 'dossiers';
        }
        $byStatus = [] === $own ? ['approved' => 0, 'refused' => 0, 'pending' => 0, 'authorizations' => 0] : self::group($own, $threshold);
        if (null === $byStatus) {
            $masked[] = 'byStatus';
        }

        return new PublishedClosureStatistics(PublishedClosureStatistics::SCOPE_COMMUNE, $threshold, $dossiers, $byStatus, null, $masked);
    }

    /**
     * @param list<MunicipalityCounters> $rows
     *
     * @return array{approved: int, refused: int, pending: int, authorizations: int}|null
     */
    private static function group(array $rows, int $threshold): ?array
    {
        $cells = ['approved' => 0, 'refused' => 0, 'pending' => 0, 'authorizations' => 0];
        foreach ($rows as $row) {
            $cells['approved'] += $row->approved;
            $cells['refused'] += $row->refused;
            $cells['pending'] += $row->pending;
            $cells['authorizations'] += $row->authorizations;
        }
        foreach ($cells as $count) {
            if (!self::publishable($count, $threshold)) {
                return null;
            }
        }

        return $cells;
    }
}
