<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Municipality\Domain\MunicipalityCommercialAccess;
use App\Municipality\Domain\MunicipalityStatus;

/**
 * Situation d'une commune lue par CWS : statut, droit de créer des événements, continuité des événements ouverts (toujours
 * assurée, DM-073), administrateurs actifs et invitations en attente (une invitation n'est jamais un administrateur).
 */
final readonly class MunicipalitySituation
{
    public function __construct(
        public MunicipalityStatus $status,
        public bool $canCreateEvents,
        public ?MunicipalityCommercialAccess $currentAccess,
        public ?MunicipalityCommercialAccess $scheduledAccess,
        public int $activeAdministrators,
        public int $pendingInvitations,
    ) {
    }

    /** Commune ouverte mais sans administrateur effectif : état d'attente à rendre visible. */
    public function awaitingAdministrator(): bool
    {
        return 0 === $this->activeAdministrators;
    }

    /** Prête : active, droit de créer des événements, au moins un administrateur. */
    public function isReady(): bool
    {
        return MunicipalityStatus::Active === $this->status && $this->canCreateEvents && $this->activeAdministrators > 0;
    }
}
