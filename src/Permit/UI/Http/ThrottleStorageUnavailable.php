<?php

declare(strict_types=1);

namespace App\Permit\UI\Http;

/** Le stockage des compteurs de limitation est en panne. L'exception d'origine est en `previous` ; aucun jeton ne circule jusqu'ici. */
final class ThrottleStorageUnavailable extends \RuntimeException
{
}
