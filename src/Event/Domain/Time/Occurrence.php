<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

/**
 * Occurrence retenue pour une heure locale qui se produit deux fois (retour à l'heure d'hiver).
 */
enum Occurrence: string
{
    case First = 'first';
    case Second = 'second';
}
