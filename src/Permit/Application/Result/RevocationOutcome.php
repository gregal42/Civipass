<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/** Résultat d'une demande de révocation : idempotente, une seconde demande (ou une demande concurrente perdante) n'écrit rien. */
enum RevocationOutcome
{
    case Revoked;
    case AlreadyRevoked;
}
