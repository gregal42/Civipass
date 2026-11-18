<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/**
 * Synthèse « demandes » de l'accueil communal (LOT 08.1) : demandes en attente de LA commune par événement non clos, et décisions des derniers jours.
 * Comptages et lectures bornés (index existants) ; aucun calcul de validité.
 */
final readonly class HomeRequestSummary
{
    /**
     * @param array<string, int>   $pendingByEvent identifiant d'événement => nombre de demandes en attente
     * @param list<RecentDecision> $recent         au plus `limit` décisions, la plus récente d'abord
     */
    public function __construct(public array $pendingByEvent, public array $recent, public int $recentDays)
    {
    }

    public function pendingTotal(): int
    {
        return array_sum($this->pendingByEvent);
    }
}
