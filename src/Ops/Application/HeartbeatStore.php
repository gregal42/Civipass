<?php

declare(strict_types=1);

namespace App\Ops\Application;

use Psr\Clock\ClockInterface;

/** Battement de cœur d'une tâche planifiée (LOT 05.6.7) : un petit fichier écrit SEULEMENT en cas de succès, lu par `app:ops:status`. Aucune base, aucune donnée personnelle. */
final class HeartbeatStore
{
    public const JOB_PATTERN = '/^[a-z][a-z0-9-]{1,40}$/';

    public function __construct(private readonly OpsSettings $settings, private readonly ClockInterface $clock)
    {
    }

    public function beat(string $job): void
    {
        if (1 !== preg_match(self::JOB_PATTERN, $job)) {
            throw new \InvalidArgumentException('Nom de tâche invalide.');
        }
        $directory = $this->settings->heartbeatDir();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire des battements de cœur inaccessible.');
        }
        $temporary = $directory.'/.'.$job.'.tmp';
        file_put_contents($temporary, json_encode(['job' => $job, 'at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')], \JSON_THROW_ON_ERROR));
        rename($temporary, $directory.'/'.$job.'.json'); // atomique : jamais un fichier à moitié écrit
    }

    public function last(string $job): ?\DateTimeImmutable
    {
        $path = $this->settings->heartbeatDir().'/'.$job.'.json';
        if (1 !== preg_match(self::JOB_PATTERN, $job) || !is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return \is_array($data) && \is_string($data['at'] ?? null) ? new \DateTimeImmutable($data['at'], new \DateTimeZone('UTC')) : null;
    }
}
