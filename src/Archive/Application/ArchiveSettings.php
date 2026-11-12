<?php

declare(strict_types=1);

namespace App\Archive\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paramètres des archives (LOT 05.6.3) : jamais des constantes. Durées = HYPOTHÈSES à valider avec le DPO et le service d'archives avant production. Aucune ne ferme
 * un accès : la durée de conservation n'est qu'un repère d'éligibilité à la purge. Planchers de sécurité : 1 mois, 1 mois de revue de gel, 5 minutes de consultation.
 */
final readonly class ArchiveSettings
{
    public function __construct(
        #[Autowire('%env(int:ARCHIVE_RETENTION_MONTHS)%')] private int $retentionMonths,
        #[Autowire('%env(int:ARCHIVE_HOLD_REVIEW_MONTHS)%')] private int $holdReviewMonths,
        #[Autowire('%env(int:ARCHIVE_CONSULTATION_MINUTES)%')] private int $consultationMinutes,
    ) {
    }

    /**
     * Repère INFORMATIF de conservation (correction A3 du 12 novembre 2026) : `clôture + durée` n'est JAMAIS une fermeture d'accès ; c'est la date à partir de
     * laquelle un dossier devient ÉLIGIBLE à la purge (05.6.4), sous réserve de gel, de préavis et de validation (DPO, archives).
     */
    public function purgeEligibleAt(\DateTimeImmutable $closedAt): \DateTimeImmutable
    {
        return $closedAt->modify(sprintf('+%d months', max(1, $this->retentionMonths)));
    }

    public function nextHoldReview(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->modify(sprintf('+%d months', max(1, $this->holdReviewMonths)));
    }

    public function consultationValidUntil(\DateTimeImmutable $openedAt): \DateTimeImmutable
    {
        return $openedAt->modify(sprintf('+%d minutes', max(5, $this->consultationMinutes)));
    }
}
