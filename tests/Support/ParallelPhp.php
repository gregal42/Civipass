<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Lance plusieurs processus PHP simultanés (scripts de tests/Support) pour éprouver la concurrence réelle sur MySQL.
 * La sortie d'erreur va dans un fichier : un tube non lu bloquerait un processus bavard.
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
        $running = [];
        foreach ($argumentLists as $arguments) {
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
            $running[] = [$handle, $pipes, $errorFile];
        }

        $results = [];
        foreach ($running as [$handle, $pipes, $errorFile]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $status = proc_close($handle);
            $stderr = (string) file_get_contents($errorFile);
            unlink($errorFile);
            $results[] = ['status' => $status, 'stdout' => $stdout, 'stderr' => substr($stderr, -800)];
        }

        return $results;
    }
}
