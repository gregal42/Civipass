<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Lance plusieurs processus PHP simultanés (scripts de tests/Support) pour éprouver la concurrence réelle sur MySQL.
 * La sortie d'erreur va dans un fichier : un tube non lu bloquerait un processus bavard.
 *
 * `start` / `isRunning` / `finish` permettent aussi de maîtriser l'ordre : démarrer un processus pendant que le test
 * détient un verrou, constater qu'il attend, puis le laisser poursuivre.
 */
final class ParallelPhp
{
    /**
     * @param list<list<string>> $argumentLists un jeu d'arguments par processus
     *
     * @return list<array{status: int, stdout: string, stderr: string}>
     */
    public static function run(string $script, array $argumentLists): array
    {
        $running = array_map(static fn (array $arguments): array => self::start($script, $arguments), $argumentLists);

        return array_map(self::finish(...), $running);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{resource, array<int, resource>, string}
     */
    public static function start(string $script, array $arguments): array
    {
        $errorFile = tempnam(sys_get_temp_dir(), 'civipass-par-');
        if (false === $errorFile) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        $handle = proc_open(
            [\PHP_BINARY, \dirname(__DIR__).'/Support/'.$script, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
            $pipes,
            \dirname(__DIR__, 2),
        );
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Processus non démarré.');
        }

        return [$handle, $pipes, $errorFile];
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     */
    public static function isRunning(array $process): bool
    {
        return (bool) proc_get_status($process[0])['running'];
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    public static function finish(array $process): array
    {
        [$handle, $pipes, $errorFile] = $process;
        $stdout = (string) stream_get_contents($pipes[1]);
        $status = proc_close($handle);
        $stderr = (string) file_get_contents($errorFile);
        unlink($errorFile);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => substr($stderr, -800)];
    }
}
