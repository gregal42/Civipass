<?php

declare(strict_types=1);

namespace App\Request\Application;

/** Membre de la commune sans rôle INSTRUCTOR ni MUNICIPALITY_ADMIN : la correction d'identité répond 403 (DM-111). */
final class IdentityCorrectionDenied extends \RuntimeException
{
}
