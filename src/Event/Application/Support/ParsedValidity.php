<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;

/** Configuration demandée, déjà validée et inspectée dans le fuseau de l'événement. */
final readonly class ParsedValidity
{
    /**
     * @param list<ValiditySlot> $slots
     */
    public function __construct(public ValidityMode $mode, public array $slots)
    {
    }
}
