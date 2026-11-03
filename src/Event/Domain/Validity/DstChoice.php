<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\Occurrence;

/**
 * Occurrence choisie pour l'horaire ambigu d'une date donnée (date locale de l'horaire concerné).
 */
final readonly class DstChoice
{
    public function __construct(
        public LocalDate $date,
        public ChoiceField $field,
        public Occurrence $occurrence,
    ) {
    }

    public function key(): string
    {
        return $this->date->toString().'|'.$this->field->value;
    }
}
