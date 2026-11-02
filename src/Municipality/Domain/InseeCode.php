<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use App\Shared\Domain\DomainRuleViolation;

final readonly class InseeCode implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $value = strtoupper(trim($raw));
        if (1 !== preg_match('/^(\d{2}|2A|2B)\d{3}$/', $value)) {
            throw new DomainRuleViolation('Code INSEE invalide : 5 caractères attendus (ex. 75056, 2A004, 97101).');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
