<?php

declare(strict_types=1);

namespace App\Event\Domain;

/** Motif codé et OBLIGATOIRE d'un report d'alerte de dormance (LOT 05.6.5) : aucun texte libre, donc aucune donnée personnelle dans l'audit. */
enum DormancyReason: string
{
    case AwaitingEndDate = 'AWAITING_END_DATE';
    case SeasonalPause = 'SEASONAL_PAUSE';
    case OngoingNeed = 'ONGOING_NEED';
    case ExternalDelay = 'EXTERNAL_DELAY';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingEndDate => 'Date de fin en attente de confirmation',
            self::SeasonalPause => 'Pause prévue, reprise annoncée',
            self::OngoingNeed => 'Besoin opérationnel maintenu',
            self::ExternalDelay => 'Dans l’attente d’une décision extérieure',
            self::Other => 'Autre motif',
        };
    }
}
