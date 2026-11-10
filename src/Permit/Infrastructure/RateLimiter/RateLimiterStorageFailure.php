<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure\RateLimiter;

/** Le stockage des compteurs de limitation n'a pas pu écrire (disque plein, lecture seule…). Le message ne porte aucun jeton ni adresse. */
final class RateLimiterStorageFailure extends \RuntimeException
{
}
