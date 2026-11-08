<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

/** Zone qui empêche l'approbation : son libellé et la raison, lisible telle quelle (message métier). */
final readonly class ZoneProblem
{
    public const SUSPENDED = 'SUSPENDED';
    public const NO_PERIOD = 'NO_PERIOD';

    public function __construct(public string $label, public string $cause)
    {
    }

    public function explanation(): string
    {
        return self::SUSPENDED === $this->cause
            ? 'cette zone est suspendue ou n’est plus utilisable.'
            : 'cette zone n’a aucune période d’accès dans la durée d’autorisation choisie.';
    }
}
