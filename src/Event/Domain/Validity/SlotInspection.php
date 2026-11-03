<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

/**
 * Résultat de l'analyse d'un créneau dans un fuseau : problèmes bloquants et choix d'occurrence requis.
 */
final readonly class SlotInspection
{
    /**
     * @param list<SlotProblem> $problems
     * @param list<SlotPoint>   $requiredChoices horaires ambigus (avec ou sans choix déjà enregistré)
     */
    public function __construct(
        public array $problems,
        public array $requiredChoices,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->problems;
    }
}
