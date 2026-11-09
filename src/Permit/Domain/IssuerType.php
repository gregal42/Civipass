<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Qui a émis un lien bénéficiaire : un agent d'une commune (LOT 05.2) ou le système, pour la notification automatique (LOT 05.3.2, D12). */
enum IssuerType: string
{
    case Agent = 'AGENT';
    case System = 'SYSTEM';
}
