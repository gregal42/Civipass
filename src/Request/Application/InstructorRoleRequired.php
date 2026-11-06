<?php

declare(strict_types=1);

namespace App\Request\Application;

/** Membre de la commune sans rôle INSTRUCTOR : l'écran répond 403 (il ne voit aucun dossier). */
final class InstructorRoleRequired extends \RuntimeException
{
}
