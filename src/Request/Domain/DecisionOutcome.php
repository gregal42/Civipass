<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Issue d'une décision communale : une décision n'est jamais « en attente » (l'état PENDING est celui de la part). */
enum DecisionOutcome: string
{
    case Approved = 'APPROVED';
    case Refused = 'REFUSED';

    public function toStatus(): MunicipalRequestStatus
    {
        return match ($this) {
            self::Approved => MunicipalRequestStatus::Approved,
            self::Refused => MunicipalRequestStatus::Refused,
        };
    }
}
