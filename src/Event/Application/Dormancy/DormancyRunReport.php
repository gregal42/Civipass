<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

final class DormancyRunReport
{
    public int $detected = 0;
    public int $alerted = 0;
    public int $failed = 0;
    public int $skipped = 0;
    /** @var array<string, int> */
    public array $reasons = [];

    public function skip(string $reason): void
    {
        ++$this->skipped;
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
    }
}
