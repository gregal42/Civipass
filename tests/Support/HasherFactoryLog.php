<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Journal des hachages demandés à la fabrique de hacheurs pendant un test (service de test, jamais en production). */
final class HasherFactoryLog
{
    /** @var list<string> */
    private array $calls = [];

    public function add(string $call): void
    {
        $this->calls[] = $call;
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }
}
