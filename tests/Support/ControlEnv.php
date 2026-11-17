<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Variables d'environnement du contrôle hors connexion (LOT 09) : posées pour UN test puis restaurées. L'interrupteur `OFFLINE_CONTROL_ENABLED`
 * n'est activé que dans les tests qui l'exigent.
 */
trait ControlEnv
{
    /** @var array<string, string|null> */
    private array $controlPreviousEnv = [];

    protected function controlEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->controlPreviousEnv)) {
            $this->controlPreviousEnv[$name] = $_SERVER[$name] ?? null;
        }
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }

    protected function restoreControlEnv(): void
    {
        foreach ($this->controlPreviousEnv as $name => $previous) {
            if (null === $previous) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $previous;
                putenv($name.'='.$previous);
            }
        }
        $this->controlPreviousEnv = [];
    }
}
