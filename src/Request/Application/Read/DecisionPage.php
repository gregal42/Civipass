<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/**
 * Données d'un écran de décision : la fiche de la commune, les motifs de refus ACTIFS (dans l'ordre du référentiel) et, si l'instruction
 * n'est pas possible maintenant, la raison à afficher. `unavailableReason` nul = actions possibles (revérifiées côté serveur à la décision).
 */
final readonly class DecisionPage
{
    /**
     * @param list<array{code: string, label: string, needsDetail: bool}> $refusalReasons
     */
    public function __construct(
        public MunicipalRequestDetail $detail,
        public array $refusalReasons,
        public ?string $unavailableReason,
    ) {
    }

    public function canDecide(): bool
    {
        return null === $this->unavailableReason && \App\Request\Domain\MunicipalRequestStatus::Pending === $this->detail->status;
    }
}
