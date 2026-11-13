<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\LinkStatus;
use App\Onboarding\Domain\MunicipalityAdminInvitation;

/** Analyse (lecture seule) d'un jeton d'invitation d'administrateur : état et, si utilisable, l'invitation. */
final readonly class AdminInvitationResolution
{
    public function __construct(public LinkStatus $status, public ?MunicipalityAdminInvitation $invitation = null)
    {
    }

    public function isUsable(): bool
    {
        return LinkStatus::Usable === $this->status && null !== $this->invitation;
    }
}
