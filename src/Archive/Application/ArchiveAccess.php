<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\RequestLegalHold;
use App\Municipality\Domain\MunicipalityMembership;
use App\Request\Domain\PermitRequestMunicipality;

/** Accès ACCORDÉ à un dossier communal archivé (résultat de `ArchiveAccessPolicy`, jamais construit ailleurs). */
final readonly class ArchiveAccess
{
    public function __construct(
        public MunicipalityMembership $membership,
        public PermitRequestMunicipality $dossier,
        public bool $viaDesignation,
        public ?RequestLegalHold $hold,
        /** Repère informatif d'éligibilité à la purge : ne ferme JAMAIS l'accès. */
        public \DateTimeImmutable $purgeEligibleAt,
    ) {
    }
}
