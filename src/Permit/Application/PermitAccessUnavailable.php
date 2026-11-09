<?php

declare(strict_types=1);

namespace App\Permit\Application;

/** La participation de la commune n'est pas ACTIVE (relue sous verrou) : consultation seule, aucune émission ni révocation de lien. */
final class PermitAccessUnavailable extends \RuntimeException
{
}
