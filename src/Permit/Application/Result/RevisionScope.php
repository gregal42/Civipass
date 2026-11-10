<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

use App\Municipality\Domain\MunicipalityMembership;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitAuthorization;

/** Périmètre d'une révision : l'adhésion de l'agent, le laissez-passer et L'autorisation de SA commune (jamais celle d'une autre). */
final readonly class RevisionScope
{
    public function __construct(
        public MunicipalityMembership $membership,
        public Permit $permit,
        public PermitAuthorization $authorization,
    ) {
    }
}
