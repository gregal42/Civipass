<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Retention\Application\PurgeStepObserver;

/** Observateur de test : lève une panne APRÈS la n-ième étape franchie (1 = la première) ; `steps` journalise les étapes franchies. */
final class ConfigurableStepObserver implements PurgeStepObserver
{
    public static ?int $failAfter = null;
    /** @var list<string> */
    public static array $steps = [];

    public static function reset(): void
    {
        self::$failAfter = null;
        self::$steps = [];
    }

    public function afterStep(string $step): void
    {
        self::$steps[] = $step;
        if (null !== self::$failAfter && \count(self::$steps) >= self::$failAfter) {
            self::$failAfter = null;
            throw new \RuntimeException('Panne injectée après '.$step);
        }
    }
}
