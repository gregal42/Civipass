<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Application\ApprovedPartListener;
use App\Request\Domain\PermitRequestDecision;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** LOT 04 : aucun laissez-passer n'existe encore ; le LOT 05 remplace cette implémentation. */
#[AsAlias(ApprovedPartListener::class)]
final readonly class NullApprovedPartListener implements ApprovedPartListener
{
    public function partApproved(PermitRequestDecision $decision): void
    {
    }
}
