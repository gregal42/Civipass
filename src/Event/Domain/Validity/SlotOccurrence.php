<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;

/**
 * Une occurrence concrète d'un créneau, en dates et heures locales (début inclus, fin exclue).
 */
final readonly class SlotOccurrence
{
    public function __construct(
        public LocalDate $startDate,
        public LocalTime $startTime,
        public LocalDate $endDate,
        public LocalTime $endTime,
    ) {
    }
}
