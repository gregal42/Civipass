<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Shared\Domain\DomainRuleViolation;

final class InvalidValiditySlot extends DomainRuleViolation
{
    /**
     * @param list<SlotProblem> $problems
     */
    public function __construct(public readonly array $problems)
    {
        $messages = array_map(static fn (SlotProblem $p): string => $p->message, $problems);
        parent::__construct(implode(' ', $messages));
    }
}
