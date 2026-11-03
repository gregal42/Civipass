<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

/**
 * Borne d'un créneau concernée par un choix d'occurrence horaire.
 */
enum ChoiceField: string
{
    case Start = 'start';
    case End = 'end';
}
