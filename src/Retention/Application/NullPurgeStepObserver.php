<?php

declare(strict_types=1);

namespace App\Retention\Application;

final class NullPurgeStepObserver implements PurgeStepObserver
{
    public function afterStep(string $step): void
    {
    }
}
