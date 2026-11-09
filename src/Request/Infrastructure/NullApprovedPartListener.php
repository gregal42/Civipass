<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Application\ApprovedPartListener;
use App\Request\Domain\PermitRequestDecision;

/** Doublure sans effet (tests du LOT 04) : depuis le LOT 05.1, `PermitIssuer` est l'implémentation câblée du port. */
final readonly class NullApprovedPartListener implements ApprovedPartListener
{
    public function partApproved(PermitRequestDecision $decision): void
    {
    }
}
