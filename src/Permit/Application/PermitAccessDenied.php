<?php

declare(strict_types=1);

namespace App\Permit\Application;

/** Membre de la commune sans rôle INSTRUCTOR ni MUNICIPALITY_ADMIN : l'accès aux liens bénéficiaires répond 403 (DM-114). */
final class PermitAccessDenied extends \RuntimeException
{
}
