<?php

declare(strict_types=1);

namespace App\Control\Application;

/** Compteurs de limitation indisponibles : jamais de limitation silencieusement perdue (même principe que le contrôle public, DT-055). */
final class ControlStorageUnavailable extends \RuntimeException
{
}
