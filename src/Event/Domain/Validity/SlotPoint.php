<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;

/**
 * Un horaire local d'un créneau (début ou fin d'une occurrence) qui appelle une décision de l'utilisateur.
 */
final readonly class SlotPoint
{
    public function __construct(
        public LocalDate $date,
        public ChoiceField $field,
        public LocalTime $time,
    ) {
    }
}
