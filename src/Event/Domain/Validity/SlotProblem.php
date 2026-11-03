<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;

final readonly class SlotProblem
{
    public function __construct(
        public ProblemType $type,
        public LocalDate $date,
        public ?ChoiceField $field,
        public string $message,
    ) {
    }
}
