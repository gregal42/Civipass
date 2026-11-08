<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Chemin d'une décision : instruction classique, ou délivrance directe par un agent (LOT 04.3, même moteur). */
enum DecisionPath: string
{
    case Instruction = 'INSTRUCTION';
    case DirectIssuance = 'DIRECT_ISSUANCE';
}
