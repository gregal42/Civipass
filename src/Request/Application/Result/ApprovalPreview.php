<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

use App\Request\Domain\GrantWindow;

/**
 * Prévisualisation en LECTURE SEULE d'une approbation : la fenêtre globale que produirait la durée choisie, et les zones qui
 * l'empêcheraient. Rien n'est écrit ; la confirmation repasse par toutes les validations transactionnelles.
 */
final readonly class ApprovalPreview
{
    /**
     * @param list<ZoneProblem> $problems
     */
    public function __construct(public GrantWindow $window, public array $problems)
    {
    }

    public function isApprovable(): bool
    {
        return [] === $this->problems;
    }
}
